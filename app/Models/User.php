<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Money;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $attributes = [
        'balance_nano' => 0,
    ];

    protected $hidden = [
        'balance_nano',
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'balance_nano' => 'integer',
        ];
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * Credits set aside for generations that haven't finished yet: the most each one can cost.
     */
    public function heldNano(): int
    {
        return (int) $this->generations()->inProgress()->sum('estimated_max_charge_nano');
    }

    /** What the user can still spend on new generations. */
    public function availableNano(): int
    {
        return $this->balance_nano - $this->heldNano();
    }

    public function creditsSummary(): array
    {
        $held = $this->heldNano();

        return [
            'balance' => Money::format($this->balance_nano),
            'held' => Money::format($held),
            'available' => Money::format($this->balance_nano - $held),
        ];
    }
}
