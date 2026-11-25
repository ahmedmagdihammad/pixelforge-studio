<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreditsAndReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_buying_credits_adds_to_the_balance_with_a_ledger_entry(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['amount' => 10])->assertRedirect();
        $this->actingAs($user)->post('/credits', ['amount' => 7])->assertSessionHasErrors('amount');

        $this->assertSame(Money::fromDollars(10), $user->fresh()->balance_nano);
        $this->assertSame(Money::fromDollars(10), (int) $user->ledgerEntries()->sum('amount_nano'));
    }

    public function test_only_admins_can_see_the_report(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
    }

    public function test_the_report_compares_our_provider_costs_with_pixelforge_usage(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $customer = User::factory()->create(['email' => 'customer@example.com']);

        // Created on the 3rd but completed on the 4th: Pixelforge bills it on the 4th, and so do we.
        $this->makeGeneration($customer, [
            'status' => Generation::SUCCEEDED,
            'provider_cost_nano' => Money::fromDecimal('0.305510'),
            'charge_nano' => Money::fromDecimal('0.3818875'),
            'created_at' => '2026-11-03 23:59:50',
            'completed_at' => '2026-11-04 00:00:10',
        ]);
        $this->makeGeneration($customer, ['status' => Generation::FAILED, 'completed_at' => '2026-11-04 01:00:00']);

        Http::fake(['pixelforge.test/v1/usage*' => Http::response(['object' => 'usage', 'data' => [
            ['date' => '2026-11-03', 'generations' => 0, 'cost_usd' => '0.000000000'],
            ['date' => '2026-11-04', 'generations' => 1, 'cost_usd' => '0.305510000'],
        ]])]);

        $this->actingAs($admin)->get('/admin/report?start_date=2026-11-03&end_date=2026-11-04')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Report')
                ->where('total.charged', '$0.3818875')
                ->where('total.cost', '$0.30551')
                ->where('total.profit', '$0.0763775')
                ->where('total.matches', true)
                ->where('days.0.date', '2026-11-04')
                ->where('days.0.matches', true)
                ->where('days.1.generations', 0)
                ->where('byUser.0.user', 'customer@example.com')
                ->has('byDayAndUser', 1));
    }
}
