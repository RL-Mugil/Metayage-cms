<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\ComplianceItem;
use App\Models\DocketDeadline;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class GetCase extends Tool
{
    public function name(): string { return 'get_case'; }

    public function title(): string { return 'Case details'; }

    public function description(): string
    {
        return 'One case in detail: team, workflow stages with status and due dates, open docket deadlines, open tasks and compliance items. Pass case_id or docket_number.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'case_id' => ['type' => 'integer'],
            'docket_number' => ['type' => 'string', 'description' => 'e.g. "382M001INPRV"'],
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $query = Project::query()->with(['client:id,client_code,legal_name,company_name', 'manager:id,name', 'secondaryManager:id,name',
            'patentEngineer:id,name', 'partner:id,name', 'stages' => fn ($s) => $s->orderBy('sequence_order')]);
        if (! empty($args['case_id'])) {
            $case = $query->findOrFail((int) $args['case_id']);
        } elseif ($docket = $this->str($args, 'docket_number')) {
            $case = $query->where('docket_number', $docket)->firstOrFail();
        } else {
            throw new \InvalidArgumentException('Pass case_id or docket_number.');
        }
        Gate::forUser($user)->authorize('view', $case);

        $row = SearchCases::row($case);
        $row['current_stage'] = $case->stages->firstWhere('status', 'In Progress')?->stage_name;

        return $row + [
            'invention_title' => $case->invention_title, 'project_type' => $case->project_type, 'case_type' => $case->case_type,
            'priority_date' => self::date($case->priority_date), 'attorney' => $case->partner?->name, 'circle' => $case->circle,
            'stages' => $case->stages->map(fn ($s) => ['stage' => $s->stage_name, 'status' => $s->status, 'due_date' => self::date($s->due_date)])->values()->all(),
            'open_deadlines' => DocketDeadline::where('project_id', $case->id)->where('status', 'Open')->where('supersession_status', 'Current')
                ->orderBy('due_date')->limit(self::MAX_ROWS)->get(['title', 'due_date', 'extended_due_date', 'risk_level', 'legal_basis'])
                ->map(fn ($d) => ['title' => $d->title, 'due_date' => self::date($d->extended_due_date ?: $d->due_date), 'risk' => $d->risk_level, 'basis' => $d->legal_basis])->all(),
            'open_tasks' => Task::where('project_id', $case->id)->where('status', '!=', 'Completed')->with('assignee:id,name')
                ->orderBy('due_date')->limit(self::MAX_ROWS)->get()
                ->map(fn ($t) => ['title' => $t->title, 'status' => $t->status, 'priority' => $t->priority, 'due_date' => self::date($t->due_date), 'assignee' => $t->assignee?->name])->all(),
            'compliance' => ComplianceItem::where('project_id', $case->id)->whereNull('resolved_at')->orderBy('deadline')->limit(self::MAX_ROWS)->get()
                ->map(fn ($c) => ['matter' => $c->matter, 'deadline' => self::date($c->deadline), 'action_required' => $c->action_required, 'status' => $c->status])->all(),
        ];
    }
}
