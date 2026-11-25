<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\DocketDeadline;
use App\Models\Task;
use App\Models\User;
use App\Services\ComplianceScope;
use App\Services\FinanceFigures;
use App\Support\DataAccess;

class DashboardKpis extends Tool
{
    public function name(): string { return 'dashboard_kpis'; }

    public function title(): string { return 'Dashboard KPIs'; }

    public function description(): string
    {
        return 'Headline numbers for the user\'s scope: open cases, overdue hard deadlines, deadlines in the next 30 days, open and overdue tasks, compliance items due in 30 days, clients, and (with finance access) billed, received, outstanding and overdue invoices.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) []];
    }

    public function handle(User $user, array $args): array
    {
        $today = now()->toDateString();
        $in30 = now()->addDays(30)->toDateString();
        $open = $this->cases($user)->whereNotIn('status', self::CLOSED_STATUSES);
        $clients = \App\Models\Client::query();
        DataAccess::scopeManagedClients($clients, $user);

        $out = [
            'as_of' => $today,
            'open_cases' => (clone $open)->count(),
            'overdue_hard_deadlines' => (clone $open)->whereNotNull('hard_deadline')->where('hard_deadline', '<', $today)->count(),
            'docket_deadlines_next_30_days' => DocketDeadline::where('status', 'Open')->where('supersession_status', 'Current')
                ->whereIn('project_id', $this->cases($user)->select('projects.id'))
                ->whereRaw('COALESCE(extended_due_date, due_date) BETWEEN ? AND ?', [$today, $in30])->count(),
            'open_tasks' => Task::query()->visibleTo($user)->where('status', '!=', 'Completed')->count(),
            'overdue_tasks' => Task::query()->visibleTo($user)->where('status', '!=', 'Completed')->whereNotNull('due_date')->where('due_date', '<', $today)->count(),
            'compliance_due_30_days' => app(ComplianceScope::class)->query($user)->whereNull('resolved_at')->whereDate('deadline', '<=', $in30)->count(),
            'clients' => $clients->count(),
        ];
        if (DataAccess::canOpenFinance($user)) {
            $out['finance_inr'] = app(FinanceFigures::class)->summary($user);
        }

        return $out;
    }
}
