<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\DocketDeadline;
use App\Models\User;

class UpcomingDeadlines extends Tool
{
    public function name(): string { return 'upcoming_deadlines'; }

    public function title(): string { return 'Upcoming deadlines'; }

    public function description(): string
    {
        return 'Legal deadlines on the user\'s cases in the next N days: open docket deadlines (statutory, office actions, renewals) and case hard deadlines, soonest first. Optionally include overdue ones.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'description' => 'Look-ahead window in days (default 30).'],
            'include_overdue' => ['type' => 'boolean', 'description' => 'Also list deadlines already past (default true).'],
            'limit' => $this->limitProp(),
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $days = max(1, min(365, (int) ($args['days'] ?? 30)));
        $from = ($args['include_overdue'] ?? true) ? '1900-01-01' : now()->toDateString();
        $to = now()->addDays($days)->toDateString();
        $visible = $this->cases($user)->select('id');
        $limit = $this->limit($args, 30);

        $docket = DocketDeadline::query()->with('project:id,docket_number,project_name')
            ->where('status', 'Open')->where('supersession_status', 'Current')->whereIn('project_id', $visible)
            ->whereRaw('COALESCE(extended_due_date, due_date) BETWEEN ? AND ?', [$from, $to])
            ->orderByRaw('COALESCE(extended_due_date, due_date)')->limit($limit)->get()
            ->map(fn ($d) => ['date' => self::date($d->extended_due_date ?: $d->due_date), 'kind' => 'docket deadline', 'title' => $d->title,
                'docket_number' => $d->project?->docket_number, 'case' => $d->project?->project_name, 'risk' => $d->risk_level]);

        $hard = $this->cases($user)->whereNotNull('hard_deadline')->whereNotIn('status', self::CLOSED_STATUSES)
            ->whereBetween('hard_deadline', [$from, $to])->orderBy('hard_deadline')->limit($limit)
            ->get(['id', 'docket_number', 'project_name', 'hard_deadline', 'status'])
            ->map(fn ($p) => ['date' => self::date($p->hard_deadline), 'kind' => 'case hard deadline', 'title' => 'Hard deadline ('.$p->status.')',
                'docket_number' => $p->docket_number, 'case' => $p->project_name, 'risk' => null]);

        $today = now()->toDateString();
        $all = $docket->concat($hard)->sortBy('date')->values()->take($limit)
            ->map(fn ($r) => $r + ['overdue' => $r['date'] < $today])->all();

        return ['window_days' => $days, 'today' => $today, 'returned' => count($all), 'deadlines' => $all];
    }
}
