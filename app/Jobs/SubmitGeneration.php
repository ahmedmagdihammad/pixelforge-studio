<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationUpdater;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a generation to Pixelforge, retrying limits and overloads with backoff.
 *
 * Every attempt uses the generation's own Idempotency-Key, so a retry can never create (or bill)
 * a second generation, even if an earlier attempt actually reached Pixelforge.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Give up after this many attempts and tell the user, rather than retrying forever. */
    public const MAX_ATTEMPTS = 8;

    /** We count attempts ourselves (see submit_attempts); this only stops a crash loop. */
    public int $tries = self::MAX_ATTEMPTS + 2;

    public function __construct(public readonly Generation $generation) {}

    public function handle(PixelforgeClient $pixelforge, GenerationUpdater $updater): void
    {
        $generation = $this->generation->fresh();

        // Already accepted (a webhook can beat the POST response) or already given up on.
        if ($generation->status !== Generation::SUBMITTING) {
            return;
        }

        $generation->increment('submit_attempts');

        try {
            $object = $pixelforge->createGeneration($generation->toPixelforgeRequest(), $generation->idempotency_key);
            $updater->apply($generation, $object);
        } catch (PixelforgeException $e) {
            if ($e->isRetryable() && $generation->submit_attempts < self::MAX_ATTEMPTS) {
                $this->release(self::backoffSeconds($generation->submit_attempts, $e->retryAfter));

                return;
            }

            $updater->markSubmissionFailed($generation, $e->errorCode, self::explain($e, $generation->submit_attempts));
        }
    }

    /**
     * Wait at least as long as Pixelforge asks, and longer after each attempt: 2s, 4s, 8s ... up to 60s.
     */
    public static function backoffSeconds(int $attempt, ?int $retryAfter): int
    {
        return max($retryAfter ?? 0, min(60, 2 ** $attempt)) + random_int(0, 1);
    }

    /** A message a user can act on. */
    private static function explain(PixelforgeException $e, int $attempts): string
    {
        return match ($e->errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy.',
            'invalid_api_key' => 'This app\'s Pixelforge API key is missing or invalid. Nothing was charged; please tell the operators.',
            'daily_limit_exceeded' => 'Our daily Pixelforge quota is used up. It resets at midnight UTC.',
            'validation_failed' => 'Pixelforge rejected the request: '.$e->getMessage(),
            'rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded', 'server_error', 'network_error' => "Pixelforge was too busy to accept this after {$attempts} attempts. Please try again in a minute.",
            default => 'Pixelforge refused the request: '.$e->getMessage(),
        };
    }
}
