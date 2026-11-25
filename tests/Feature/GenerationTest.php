<?php

namespace Tests\Feature;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationUpdater;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerationTest extends TestCase
{
    use RefreshDatabase;

    private array $form = [
        'prompt' => 'A lighthouse on a cliff at dawn',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png', ''],
    ];

    public function test_the_page_shows_the_users_generations(): void
    {
        $user = User::factory()->create();
        $this->makeGeneration($user);

        $this->actingAs($user)->get('/generations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Generations')->has('generations.data', 1));
    }

    public function test_estimate_returns_the_price_range_before_submitting(): void
    {
        $this->fakePricing();
        $user = User::factory()->create(['balance_nano' => Money::fromDollars(1)]);

        $this->actingAs($user)->postJson('/generations/estimate', ['prompt' => str_repeat('a', 400)] + $this->form)
            ->assertOk()
            ->assertJson(['max' => '$0.45778125', 'affordable' => true]); // 1 reference image (the blank line is dropped)
    }

    public function test_submitting_holds_the_maximum_cost_and_queues_the_request(): void
    {
        Queue::fake();
        $this->fakePricing();
        $user = User::factory()->create(['balance_nano' => Money::fromDollars(1)]);

        $this->actingAs($user)->post('/generations', $this->form)->assertRedirect();

        $generation = Generation::sole();
        $this->assertSame(Generation::SUBMITTING, $generation->status);
        $this->assertSame(['https://example.com/style.png'], $generation->reference_images);
        $this->assertSame($generation->estimated_max_charge_nano, $user->heldNano());
        Queue::assertPushed(SubmitGeneration::class);
    }

    public function test_users_who_cannot_afford_the_maximum_are_blocked(): void
    {
        Queue::fake();
        $this->fakePricing();
        $user = User::factory()->create(['balance_nano' => Money::fromDecimal('0.10')]);

        $this->actingAs($user)->post('/generations', $this->form)->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::count());
        Queue::assertNothingPushed();
    }

    public function test_the_job_submits_with_an_idempotency_key_and_records_the_pixelforge_id(): void
    {
        $user = User::factory()->create();
        $generation = $this->makeGeneration($user, ['status' => Generation::SUBMITTING, 'pixelforge_id' => null]);
        Http::fake(['pixelforge.test/v1/generations' => Http::response($this->generationObject($generation, 'queued', ['id' => 'gen_new']), 202)]);

        (new SubmitGeneration($generation))->handle(app(PixelforgeClient::class), app(GenerationUpdater::class));

        $generation->refresh();
        $this->assertSame(Generation::QUEUED, $generation->status);
        $this->assertSame('gen_new', $generation->pixelforge_id);
        Http::assertSent(fn (Request $r) => $r->header('Idempotency-Key')[0] === $generation->idempotency_key
            && $r['metadata'] === ['generation_id' => (string) $generation->id]
            && ! array_key_exists('estimated_max_charge_nano', $r->data()));
    }

    public function test_overloads_and_limits_are_retried_after_retry_after(): void
    {
        $user = User::factory()->create();
        $generation = $this->makeGeneration($user, ['status' => Generation::SUBMITTING, 'pixelforge_id' => null]);
        Http::fake(['pixelforge.test/v1/generations' => Http::response(
            ['error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'Too many.']], 429, ['Retry-After' => '5'],
        )]);

        $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
        $job->handle(app(PixelforgeClient::class), app(GenerationUpdater::class));

        $job->assertReleased();
        $this->assertSame(Generation::SUBMITTING, $generation->fresh()->status);
    }

    public function test_it_gives_up_after_a_few_attempts_and_charges_nothing(): void
    {
        $user = User::factory()->create();
        $generation = $this->makeGeneration($user, ['status' => Generation::SUBMITTING, 'pixelforge_id' => null, 'submit_attempts' => SubmitGeneration::MAX_ATTEMPTS - 1]);
        Http::fake(['pixelforge.test/v1/generations' => Http::response(
            ['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Overloaded.']], 503, ['Retry-After' => '2'],
        )]);

        $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
        $job->handle(app(PixelforgeClient::class), app(GenerationUpdater::class));

        $job->assertNotReleased();
        $generation->refresh();
        $this->assertSame(Generation::FAILED, $generation->status);
        $this->assertSame('overloaded', $generation->error_code);
        $this->assertSame(0, $user->heldNano());
    }

    public function test_a_rejected_prompt_fails_immediately_and_is_free(): void
    {
        $user = User::factory()->create();
        $generation = $this->makeGeneration($user, ['status' => Generation::SUBMITTING, 'pixelforge_id' => null]);
        Http::fake(['pixelforge.test/v1/generations' => Http::response(
            ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Rejected.']], 400,
        )]);

        $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
        $job->handle(app(PixelforgeClient::class), app(GenerationUpdater::class));

        $job->assertNotReleased();
        $this->assertSame('content_policy_violation', $generation->fresh()->error_code);
        $this->assertNull($generation->fresh()->charge_nano);
    }

    public function test_polling_catches_up_when_webhooks_are_lost(): void
    {
        $user = User::factory()->create(['balance_nano' => Money::fromDollars(1)]);
        $generation = $this->makeGeneration($user);
        $generation->forceFill(['created_at' => now()->subMinutes(5)])->save();
        Http::fake(["pixelforge.test/v1/generations/{$generation->pixelforge_id}" => Http::response($this->generationObject($generation, 'succeeded'))]);

        $this->artisan('generations:sync')->assertSuccessful();

        $this->assertSame(Generation::SUCCEEDED, $generation->fresh()->status);
        $this->assertSame(Money::fromDecimal('0.3818875'), $generation->fresh()->charge_nano);
    }
}
