<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\GenerationUpdater;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;

/**
 * Safety net for lost webhooks: Pixelforge's delivery isn't guaranteed, so every minute we poll
 * generations that have been in progress for a while and apply whatever Pixelforge says.
 */
class SyncGenerations extends Command
{
    protected $signature = 'generations:sync {--limit=20 : Most generations to poll per run (they count toward the 60/min rate limit)}';

    protected $description = 'Poll Pixelforge for generations whose webhooks may have been lost';

    public function handle(PixelforgeClient $pixelforge, GenerationUpdater $updater): int
    {
        $stale = Generation::query()
            ->whereIn('status', [Generation::QUEUED, Generation::PROCESSING])
            ->whereNotNull('pixelforge_id')
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinute()))
            ->where('created_at', '<', now()->subMinute())
            ->oldest('last_synced_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($stale as $generation) {
            try {
                $updater->apply($generation, $pixelforge->getGeneration($generation->pixelforge_id));
            } catch (PixelforgeException $e) {
                $this->warn("Generation {$generation->id}: {$e->errorCode} {$e->getMessage()}");

                if ($e->errorCode === 'rate_limit_exceeded') {
                    break;
                }
            }
        }

        $this->info("Polled {$stale->count()} generation(s).");

        return self::SUCCESS;
    }
}
