<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    public const SUBMITTING = 'submitting'; // ours: waiting for Pixelforge to accept it

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** Statuses that still hold credits on the user's balance. */
    public const IN_PROGRESS = [self::SUBMITTING, self::QUEUED, self::PROCESSING];

    /** A status may only move to one with a higher rank, so late or repeated events can't move it back. */
    private const RANK = [
        self::SUBMITTING => 0,
        self::QUEUED => 1,
        self::PROCESSING => 2,
        self::SUCCEEDED => 3,
        self::FAILED => 3,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'estimated_min_charge_nano' => 'integer',
            'estimated_max_charge_nano' => 'integer',
            'provider_cost_nano' => 'integer',
            'charge_nano' => 'integer',
            'charged_at' => 'datetime',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeInProgress(Builder $query): void
    {
        $query->whereIn('status', self::IN_PROGRESS);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED], true);
    }

    public function canMoveTo(string $status): bool
    {
        // A failure we recorded ourselves before Pixelforge ever confirmed the generation (e.g. the
        // network dropped) is only our best guess. If Pixelforge later tells us what really happened,
        // believe it, otherwise we could miss a charge Pixelforge made us.
        if ($this->status === self::FAILED && $this->pixelforge_id === null) {
            return true;
        }

        return (self::RANK[$status] ?? -1) > self::RANK[$this->status];
    }

    /** The body we send to POST /v1/generations. */
    public function toPixelforgeRequest(): array
    {
        return [
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            // Comes back in every webhook, so we can match events even before our POST has returned.
            'metadata' => ['generation_id' => (string) $this->id],
        ];
    }

    /** What the UI needs. Money goes out as preformatted strings so the browser never does float math. */
    public function toFrontend(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_min' => Money::format($this->estimated_min_charge_nano),
            'estimated_max' => Money::format($this->estimated_max_charge_nano),
            'charge' => $this->charge_nano === null ? null : Money::format($this->charge_nano),
            'image_url' => $this->image_url,
            'image_expired' => $this->image_url_expires_at?->isPast() ?? false,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
