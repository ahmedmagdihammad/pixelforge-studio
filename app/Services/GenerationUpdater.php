<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single place that changes a generation's status and charges for it.
 *
 * Webhooks, polling and the create response all funnel through apply(), which is safe to call
 * any number of times, in any order: statuses only ever move forward, and a generation is charged
 * at most once (guarded by a row lock, charged_at, and a unique index on the ledger).
 */
class GenerationUpdater
{
    public function __construct(private readonly Pricing $pricing) {}

    /**
     * Bring our record in line with a Pixelforge generation object.
     */
    public function apply(Generation $generation, array $object): Generation
    {
        return DB::transaction(function () use ($generation, $object) {
            // Lock the row so a webhook and a poll arriving together can't both act on it.
            $generation = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($generation->canMoveTo($object['status'])) {
                $this->moveTo($generation, $object);
            }

            $generation->pixelforge_id ??= $object['id'];
            $generation->last_synced_at = now();
            $generation->save();

            return $generation;
        });
    }

    /**
     * Record a failure that happened before Pixelforge accepted the generation (it rejected the
     * request, or we gave up retrying). Nothing was billed, so the user pays nothing.
     */
    public function markSubmissionFailed(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $generation = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($generation->status !== Generation::SUBMITTING) {
                return; // a webhook got here first; trust it
            }

            $generation->update([
                'status' => Generation::FAILED,
                'error_code' => $code,
                'error_message' => $message,
                'completed_at' => now(),
            ]);
        });
    }

    private function moveTo(Generation $generation, array $object): void
    {
        $generation->fill([
            'status' => $object['status'],
            'rates' => $object['rates'],
            'started_at' => self::time($object['started_at'] ?? null),
            'completed_at' => self::time($object['completed_at'] ?? null),
            'error_code' => null,
            'error_message' => null,
        ]);

        if ($object['status'] === Generation::SUCCEEDED) {
            $providerCost = $this->pricing->providerCost($object['usage'], $object['rates']);

            $generation->fill([
                'usage' => $object['usage'],
                'image_url' => $object['image_url'],
                'image_url_expires_at' => self::time($object['image_url_expires_at'] ?? null),
                'provider_cost_nano' => $providerCost,
                'charge_nano' => $this->pricing->userCharge($providerCost),
            ]);

            $this->chargeUser($generation);
        }

        if ($object['status'] === Generation::FAILED) {
            // Failed generations are free: no charge, and the held credits are released.
            $generation->fill([
                'error_code' => $object['error']['code'] ?? 'generation_failed',
                'error_message' => $object['error']['message'] ?? null,
            ]);
        }
    }

    private function chargeUser(Generation $generation): void
    {
        if ($generation->charged_at !== null) {
            return;
        }

        LedgerEntry::create([
            'user_id' => $generation->user_id,
            'type' => LedgerEntry::GENERATION,
            'amount_nano' => -$generation->charge_nano,
            'generation_id' => $generation->id,
            'description' => "Image generation #{$generation->id}",
        ]);

        User::whereKey($generation->user_id)->decrement('balance_nano', $generation->charge_nano);

        $generation->charged_at = now();
    }

    private static function time(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->utc();
    }
}
