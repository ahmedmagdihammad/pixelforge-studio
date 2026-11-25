<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditController extends Controller
{
    /** Pack sizes in dollars. A fake purchase: no real payment is taken. */
    public const PACKS = [5, 10, 25];

    public function store(Request $request): RedirectResponse
    {
        $dollars = (int) $request->validate(['amount' => ['required', 'integer', 'in:'.implode(',', self::PACKS)]])['amount'];
        $amount = Money::fromDollars($dollars);

        DB::transaction(function () use ($request, $amount, $dollars) {
            LedgerEntry::create([
                'user_id' => $request->user()->id,
                'type' => LedgerEntry::PURCHASE,
                'amount_nano' => $amount,
                'description' => "Bought \${$dollars} of credits",
            ]);

            User::whereKey($request->user()->id)->increment('balance_nano', $amount);
        });

        return back()->with('success', 'Added '.Money::format($amount).' to your credits.');
    }
}
