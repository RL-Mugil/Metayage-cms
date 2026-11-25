<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\ComplianceItem;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use App\Support\DataAccess;

class MyDay extends Tool
{
    public function name(): string { return 'my_day'; }

    public function title(): string { return 'My day'; }

    public function description(): string
    {
        return 'A personal briefing for the user: their open tasks due today or overdue, deadlines on their own cases (Client Manager / Secondary CM / Patent Engineer) in the next 7 days, compliance items assigned to them, and unread notifications.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30, 'description' => 'Deadline window (default 7).']]];
    }

    public function handle(User $user, array $args): array
    {
        $days = max(1, min(30, (int) ($args['days'] ?? 7)));
        $today = now()->toDateString();
        $tasks = Task::with('project:id,docket_number')->where('assignee_id', $user->id)->where('status', '!=', 'Completed')
            ->whereNotNull('due_date')->where('due_date', '<=', $today)->orderBy('due_date')->limit(self::MAX_ROWS)->get()
            ->map(fn ($t) => ['title' => $t->title, 'docket_number' => $t->project?->docket_number, 'due_date' => self::date($t->due_date), 'priority' => $t->priority, 'status' => $t->status]);

        // Deadlines on the user's own cases, whatever their role.
        $uid = (int) $user->id;
        $mine = fn ($q) => $q->where(fn ($w) => $w->where('assigned_manager_id', $uid)->orWhere('secondary_manager_id', $uid)->orWhere('patent_engineer_id', $uid));
        $deadlines = collect(app(UpcomingDeadlines::class)->handle($user, ['days' => $days, 'include_overdue' => true, 'limit' => 50])['deadlines']);
        $ownDockets = \App\Models\Project::query()->where($mine)->pluck('docket_number')->filter()->flip();
        $deadlines = $deadlines->filter(fn ($d) => isset($ownDockets[$d['docket_number']]))->values();

        $compliance = ComplianceItem::with('project:id,docket_number')->where('assignee_id', $user->id)->whereNull('resolved_at')
            ->whereDate('deadline', '<=', now()->addDays($days))->orderBy('deadline')->limit(self::MAX_ROWS)->get()
            ->map(fn ($c) => ['matter' => $c->matter, 'deadline' => self::date($c->deadline), 'action_required' => $c->action_required, 'docket_number' => $c->project?->docket_number]);

        return [
            'date' => $today, 'name' => $user->name,
            'tasks_due_or_overdue' => $tasks->all(),
            "deadlines_next_{$days}_days_on_my_cases" => $deadlines->all(),
            'my_compliance_items' => $compliance->all(),
            'unread_notifications' => Notification::where('user_id', $user->id)->where('is_read', false)->count(),
            'finance_access' => DataAccess::canOpenFinance($user),
        ];
    }
}
