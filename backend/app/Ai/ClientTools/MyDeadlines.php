<?php

namespace App\Ai\ClientTools;

use App\Models\DocketDeadline;
use App\Models\RenewalSchedule;
use App\Models\User;

class MyDeadlines extends ClientTool
{
    public function name(): string { return 'my_deadlines'; }

    public function title(): string { return 'My deadlines and renewals'; }

    public function description(): string
    {
        return 'Upcoming deadlines on the client\'s own cases in the next N days: official deadlines (office actions, statutory dates), case hard deadlines and renewal fees due, soonest first.';
    }

    protected function permission(): string { return 'cases'; }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 730, 'description' => 'Look-ahead window in days (default 90).'],
            'limit' => $this->limitProp(),
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $days = max(1, min(730, (int) ($args['days'] ?? 90)));
        $to = now()->addDays($days)->toDateString();
        $today = now()->toDateString();
        $limit = $this->limit($args, 30);
        $caseIds = $this->ownCases($user)->select('id');

        $docket = DocketDeadline::query()->with('project:id,docket_number,project_name')
            ->where('status', 'Open')->where('supersession_status', 'Current')->whereIn('project_id', $caseIds)
            ->whereRaw('COALESCE(extended_due_date, due_date) <= ?', [$to])
            ->orderByRaw('COALESCE(extended_due_date, due_date)')->limit($limit)->get()
            ->map(fn ($d) => ['date' => self::date($d->extended_due_date ?: $d->due_date), 'kind' => 'Official deadline', 'what' => $d->title,
                'docket_number' => $d->project?->docket_number]);
        $hard = $this->ownCases($user)->whereNotNull('hard_deadline')->whereNotIn('status', self::CLOSED_STATUSES)->where('hard_deadline', '<=', $to)
            ->orderBy('hard_deadline')->limit($limit)->get(['docket_number', 'project_name', 'hard_deadline'])
            ->map(fn ($p) => ['date' => self::date($p->hard_deadline), 'kind' => 'Case deadline', 'what' => $p->project_name, 'docket_number' => $p->docket_number]);
        $renewals = RenewalSchedule::query()->with('application:id,application_number')
            ->whereHas('application', fn ($a) => $a->where('client_id', $this->ownClient($user)?->id ?? 0))
            ->where('status', '!=', 'Paid')->where('due_date', '<=', $to)->orderBy('due_date')->limit($limit)->get()
            ->map(fn ($r) => ['date' => self::date($r->due_date), 'kind' => 'Renewal fee', 'what' => 'Year '.$r->renewal_year.' renewal',
                'docket_number' => $r->application?->application_number]);

        $all = $docket->concat($hard)->concat($renewals)->sortBy('date')->values()->take($limit)
            ->map(fn ($r) => $r + ['overdue' => $r['date'] < $today])->all();

        return ['today' => $today, 'window_days' => $days, 'returned' => count($all), 'deadlines' => $all];
    }
}
