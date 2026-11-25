<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\WebhookSignature;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['balance_nano' => Money::fromDollars(10)]);
    }

    private function deliver(array $object, string $type, ?string $eventId = null, ?int $signedAt = null): TestResponse
    {
        $body = json_encode([
            'id' => $eventId ?? 'evt_'.Str::ulid(),
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-04T14:03:29Z',
            'data' => ['object' => $object],
        ]);

        $signature = app(WebhookSignature::class)->sign($body, $signedAt ?? time());

        return $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $signature,
        ], content: $body);
    }

    public function test_it_rejects_a_bad_signature(): void
    {
        $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => 't='.time().',v1=deadbeef',
        ], content: '{"id":"evt_1","type":"ping"}')->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_it_rejects_an_old_signature_to_stop_replays(): void
    {
        $generation = $this->makeGeneration($this->user);

        $this->deliver($this->generationObject($generation, 'succeeded'), 'generation.succeeded', signedAt: time() - 301)
            ->assertStatus(400);

        $this->assertSame(Generation::QUEUED, $generation->fresh()->status);
    }

    public function test_a_succeeded_generation_is_charged_its_exact_cost_plus_markup(): void
    {
        $generation = $this->makeGeneration($this->user);

        $this->deliver($this->generationObject($generation, 'succeeded'), 'generation.succeeded')->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::SUCCEEDED, $generation->status);
        $this->assertSame(Money::fromDecimal('0.305510'), $generation->provider_cost_nano);
        $this->assertSame(Money::fromDecimal('0.3818875'), $generation->charge_nano);
        $this->assertSame(Money::fromDollars(10) - Money::fromDecimal('0.3818875'), $this->user->fresh()->balance_nano);
        $this->assertSame('2026-11-04 14:03:29', $generation->completed_at->toDateTimeString());
    }

    public function test_the_same_event_delivered_twice_is_recorded_once(): void
    {
        $generation = $this->makeGeneration($this->user);
        $object = $this->generationObject($generation, 'succeeded');

        $this->deliver($object, 'generation.succeeded', 'evt_same')->assertNoContent();
        $this->deliver($object, 'generation.succeeded', 'evt_same')->assertNoContent();

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, $this->user->ledgerEntries()->count());
        $this->assertSame(Money::fromDollars(10) - Money::fromDecimal('0.3818875'), $this->user->fresh()->balance_nano);
    }

    public function test_two_different_events_reporting_success_still_charge_once(): void
    {
        // e.g. a webhook and a poll, or two event ids for the same outcome.
        $generation = $this->makeGeneration($this->user);
        $object = $this->generationObject($generation, 'succeeded');

        $this->deliver($object, 'generation.succeeded', 'evt_a');
        $this->deliver($object, 'generation.succeeded', 'evt_b');

        $this->assertSame(1, $this->user->ledgerEntries()->count());
    }

    public function test_a_late_processing_event_never_moves_a_generation_back(): void
    {
        $generation = $this->makeGeneration($this->user);

        $this->deliver($this->generationObject($generation, 'succeeded'), 'generation.succeeded');
        $this->deliver($this->generationObject($generation, 'processing'), 'generation.processing');

        $this->assertSame(Generation::SUCCEEDED, $generation->fresh()->status);
    }

    public function test_failed_generations_are_free_and_release_the_hold(): void
    {
        $generation = $this->makeGeneration($this->user);
        $this->assertSame(500_000_000, $this->user->heldNano());

        $this->deliver($this->generationObject($generation, 'failed'), 'generation.failed');

        $generation->refresh();
        $this->assertSame(Generation::FAILED, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertNull($generation->charge_nano);
        $this->assertSame(0, $this->user->heldNano());
        $this->assertSame(Money::fromDollars(10), $this->user->fresh()->balance_nano);
    }

    public function test_a_webhook_that_beats_the_create_response_is_matched_by_metadata(): void
    {
        $generation = $this->makeGeneration($this->user, ['status' => Generation::SUBMITTING, 'pixelforge_id' => null]);

        $this->deliver($this->generationObject($generation, 'processing', ['id' => 'gen_early']), 'generation.processing');

        $generation->refresh();
        $this->assertSame(Generation::PROCESSING, $generation->status);
        $this->assertSame('gen_early', $generation->pixelforge_id);
    }

    public function test_pixelforges_answer_overrides_a_failure_we_only_guessed(): void
    {
        // We gave up after network errors, but the request had in fact reached Pixelforge.
        $generation = $this->makeGeneration($this->user, ['status' => Generation::FAILED, 'pixelforge_id' => null, 'error_code' => 'network_error']);

        $this->deliver($this->generationObject($generation, 'succeeded', ['id' => 'gen_real']), 'generation.succeeded');

        $this->assertSame(Generation::SUCCEEDED, $generation->fresh()->status);
        $this->assertSame(1, $this->user->ledgerEntries()->count());
    }

    public function test_ping_and_unknown_generations_are_acknowledged(): void
    {
        $this->deliver(['id' => 'gen_unknown', 'status' => 'succeeded', 'metadata' => []], 'generation.succeeded')->assertNoContent();
        $this->deliver([], 'ping')->assertNoContent();
    }
}
