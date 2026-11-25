<?php

namespace Tests;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** The body of GET /v1/pricing, exactly as published in the Pixelforge docs. */
    protected function pricingResponse(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => self::rates(),
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    protected static function rates(): array
    {
        return [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];
    }

    protected function fakePricing(): void
    {
        Http::fake(['pixelforge.test/v1/pricing' => Http::response($this->pricingResponse())]);
    }

    /** A Pixelforge generation object for one of our generations. */
    protected function generationObject(Generation $generation, string $status, array $overrides = []): array
    {
        $object = [
            'id' => $generation->pixelforge_id ?? 'gen_'.Str::ulid(),
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images,
            'metadata' => ['generation_id' => (string) $generation->id],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => self::rates(),
            'error' => null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => $status === 'queued' ? null : '2026-11-04T14:03:13Z',
            'completed_at' => null,
        ];

        if ($status === 'succeeded') {
            $object['image_url'] = 'https://pixelforge.test/images/x.png';
            $object['image_url_expires_at'] = '2026-11-05T14:03:29Z';
            $object['completed_at'] = '2026-11-04T14:03:29Z';
            // The docs' example: costs exactly $0.305510 at the published rates.
            $object['usage'] = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411];
        }

        if ($status === 'failed') {
            $object['completed_at'] = '2026-11-04T14:03:29Z';
            $object['error'] = ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'];
        }

        return array_replace($object, $overrides);
    }

    /** A generation that has been accepted by Pixelforge and is holding credits. */
    protected function makeGeneration(User $user, array $attributes = []): Generation
    {
        return $user->generations()->create($attributes + [
            'idempotency_key' => (string) Str::uuid(),
            'pixelforge_id' => 'gen_'.Str::ulid(),
            'status' => Generation::QUEUED,
            'prompt' => 'A lighthouse on a cliff at dawn',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'estimated_min_charge_nano' => 300_000_000,
            'estimated_max_charge_nano' => 500_000_000,
        ]);
    }
}
