<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\User;
use App\Services\FinanceFigures;
use App\Support\DataAccess;
use Illuminate\Support\Carbon;

class PeriodComparison extends Tool
{
    public function name(): string { return 'period_comparison'; }

    public function title(): string { return 'This period vs last'; }

    public function description(): string
    {
        return 'Compares this month, quarter or year with the previous one (and the same period last year): new cases opened, cases granted, and — with finance access — amount billed and collected (INR). Shows change in %.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'period' => ['type' => 'string', 'enum' => ['month', 'quarter', 'year']],
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $period = in_array($args['period'] ?? '', ['month', 'quarter', 'year'], true) ? $args['period'] : 'month';
        $start = match ($period) { 'quarter' => now()->startOfQuarter(), 'year' => now()->startOfYear(), default => now()->startOfMonth() };
        $span = fn (Carbon $s) => [$s->copy(), match ($period) { 'quarter' => $s->copy()->endOfQuarter(), 'year' => $s->copy()->endOfYear(), default => $s->copy()->endOfMonth() }];
        $prevStart = match ($period) { 'quarter' => $start->copy()->subQuarter(), 'year' => $start->copy()->subYear(), default => $start->copy()->subMonth() };
        $periods = [
            'current' => [$start, now()],
            'previous' => $span($prevStart),
            'same_period_last_year' => [$start->copy()->subYear(), now()->subYear()],
        ];
        $finance = DataAccess::canOpenFinance($user);
        $figures = app(FinanceFigures::class);
        $rows = $finance ? $figures->rows($user) : collect();

        $out = [];
        foreach ($periods as $key => [$from, $to]) {
            $m = [
                'from' => $from->toDateString(), 'to' => $to->toDateString(),
                'cases_opened' => $this->cases($user)->whereBetween('projects.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count(),
                'cases_granted' => $this->cases($user)->where('status', 'Granted')->whereBetween('projects.updated_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count(),
            ];
            if ($finance) {
                $m['billed_inr'] = round((float) $rows->filter(fn ($r) => $r['date'] && $r['date']->between($from->copy()->startOfDay(), $to->copy()->endOfDay()))->sum('billed'), 2);
                $m['collected_inr'] = round((float) $figures->payments($user)->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])->sum('amount_bcy'), 2);
            }
            $out[$key] = $m;
        }
        $change = [];
        foreach (array_keys($out['current']) as $k) {
            if (in_array($k, ['from', 'to'], true)) {
                continue;
            }
            $prev = (float) $out['previous'][$k];
            $change[$k] = $prev == 0.0 ? null : round((((float) $out['current'][$k]) - $prev) / $prev * 100, 1);
        }

        return ['period' => $period, 'note' => 'The current period runs to today; "granted" uses the date the case was last updated to Granted.'] + $out + ['change_vs_previous_pct' => $change];
    }
}
