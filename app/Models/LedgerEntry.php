<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    public const PURCHASE = 'purchase';

    public const GENERATION = 'generation';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_nano' => 'integer'];
    }
}
