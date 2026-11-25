<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator report: what users were charged, what Pixelforge charged us, and the profit,
 * per UTC day and per user. Days follow Pixelforge's rule: a generation counts on the UTC day it
 * *completed*, so our provider costs can be compared line by line with GET /v1/usage.
 */
class ReportController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $pixelforge): Response
    {
        $input = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $end = Carbon::parse($input['end_date'] ?? now('UTC')->toDateString(), 'UTC');
        $start = Carbon::parse($input['start_date'] ?? $end->copy()->subDays(29)->toDateString(), 'UTC');
        $start = $start->max($end->copy()->subDays(91)); // Pixelforge's usage endpoint allows 92 days at most

        $rows = Generation::query()
            ->where('status', Generation::SUCCEEDED)
            ->whereBetween('completed_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('date(completed_at) as day, user_id, count(*) as generations, sum(charge_nano) as charged, sum(provider_cost_nano) as cost')
            ->groupBy('day', 'user_id')
            ->orderByDesc('day')
            ->get();

        $users = User::whereIn('id', $rows->pluck('user_id')->unique())->pluck('email', 'id');
        [$pixelforgeDays, $pixelforgeError] = $this->pixelforgeUsage($pixelforge, $start, $end);

        $days = collect();
        for ($day = $end->copy(); $day->gte($start); $day->subDay()) {
            $date = $day->toDateString();
            $ours = $this->totals($rows->where('day', $date));
            $reported = $pixelforgeDays[$date] ?? null;

            $days->push($this->present($ours) + [
                'date' => $date,
                'pixelforge_cost' => $reported === null ? null : Money::format($reported),
                'matches' => $reported === null ? null : $reported === $ours['cost'],
            ]);
        }

        $byUser = $rows->groupBy('user_id')
            ->map(fn (Collection $userRows, $userId) => $this->present($this->totals($userRows)) + ['user' => $users[$userId] ?? "#{$userId}"])
            ->sortByDesc('charged_nano')
            ->values();

        $daily = $rows->map(fn ($row) => $this->present($this->totals(collect([$row]))) + [
            'date' => $row->day,
            'user' => $users[$row->user_id] ?? "#{$row->user_id}",
        ]);

        $total = $this->totals($rows);
        $pixelforgeTotal = $pixelforgeDays === null ? null : array_sum($pixelforgeDays);

        return Inertia::render('Admin/Report', [
            'filters' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            'total' => $this->present($total) + [
                'pixelforge_cost' => $pixelforgeTotal === null ? null : Money::format($pixelforgeTotal),
                'matches' => $pixelforgeTotal === null ? null : $pixelforgeTotal === $total['cost'],
            ],
            'days' => $days,
            'byUser' => $byUser,
            'byDayAndUser' => $daily,
            'pixelforgeError' => $pixelforgeError,
        ]);
    }

    /** @return array{generations: int, charged: int, cost: int} */
    private function totals(Collection $rows): array
    {
        return [
            'generations' => (int) $rows->sum('generations'),
            'charged' => (int) $rows->sum(fn ($r) => (int) $r->charged),
            'cost' => (int) $rows->sum(fn ($r) => (int) $r->cost),
        ];
    }

    private function present(array $totals): array
    {
        return [
            'generations' => $totals['generations'],
            'charged' => Money::format($totals['charged']),
            'charged_nano' => $totals['charged'],
            'cost' => Money::format($totals['cost']),
            'profit' => Money::format($totals['charged'] - $totals['cost']),
        ];
    }

    /**
     * Pixelforge's own record of what it billed this API key, per day, in nanodollars.
     *
     * @return array{0: ?array<string, int>, 1: ?string}
     */
    private function pixelforgeUsage(PixelforgeClient $pixelforge, Carbon $start, Carbon $end): array
    {
        try {
            $usage = $pixelforge->usage($start->toDateString(), $end->toDateString());
        } catch (PixelforgeException $e) {
            return [null, "Couldn't load Pixelforge usage: {$e->getMessage()}"];
        }

        return [collect($usage['data'])->mapWithKeys(fn ($day) => [$day['date'] => Money::fromDecimal($day['cost_usd'])])->all(), null];
    }
}
