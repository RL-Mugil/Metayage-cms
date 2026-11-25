<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\User;

class TeamWorkload extends Tool
{
    public function name(): string { return 'team_workload'; }

    public function title(): string { return 'Team workload'; }

    public function description(): string
    {
        return 'Open cases per person (as Client Manager, Secondary CM or Patent Engineer) within the cases the user can see, plus each person\'s open and overdue tasks.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['limit' => $this->limitProp()]];
    }

    public function handle(User $user, array $args): array
    {
        $open = $this->cases($user)->whereNotIn('status', self::CLOSED_STATUSES)
            ->get(['id', 'assigned_manager_id', 'secondary_manager_id', 'patent_engineer_id']);
        $people = [];
        foreach ($open as $p) {
            foreach (['client_manager' => $p->assigned_manager_id, 'secondary_cm' => $p->secondary_manager_id, 'patent_engineer' => $p->patent_engineer_id] as $role => $uid) {
                if ($uid) {
                    $people[$uid]['cases'][$p->id] = true;
                    $people[$uid][$role] = ($people[$uid][$role] ?? 0) + 1;
                }
            }
        }
        $ids = array_keys($people);
        $names = User::whereIn('id', $ids)->pluck('name', 'id');
        $caseIds = $open->pluck('id');
        $tasks = \App\Models\Task::whereIn('assignee_id', $ids)->whereIn('project_id', $caseIds)->where('status', '!=', 'Completed')
            ->selectRaw('assignee_id, COUNT(*) AS open_tasks, SUM(CASE WHEN due_date < CURRENT_DATE THEN 1 ELSE 0 END) AS overdue_tasks')
            ->groupBy('assignee_id')->get()->keyBy('assignee_id');

        $rows = collect($people)->map(fn ($r, $uid) => [
            'name' => $names[$uid] ?? "User {$uid}", 'open_cases' => count($r['cases']),
            'as_client_manager' => $r['client_manager'] ?? 0, 'as_secondary_cm' => $r['secondary_cm'] ?? 0, 'as_patent_engineer' => $r['patent_engineer'] ?? 0,
            'open_tasks' => (int) ($tasks[$uid]->open_tasks ?? 0), 'overdue_tasks' => (int) ($tasks[$uid]->overdue_tasks ?? 0),
        ])->sortByDesc('open_cases')->values();

        return ['open_cases_in_scope' => $open->count(), 'people' => $rows->take($this->limit($args, 50))->all(), 'truncated' => $rows->count() > $this->limit($args, 50)];
    }
}
