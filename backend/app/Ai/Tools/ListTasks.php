<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\Task;
use App\Models\User;

class ListTasks extends Tool
{
    public function name(): string { return 'list_tasks'; }

    public function title(): string { return 'Tasks'; }

    public function description(): string
    {
        return 'Tasks the user can see, by status, priority, only their own, or overdue only. Returns title, case, assignee, priority, due date and status.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['Pending', 'In Progress', 'Review', 'Completed', 'Blocked']],
            'priority' => ['type' => 'string', 'enum' => ['Low', 'Medium', 'High', 'Urgent']],
            'mine' => ['type' => 'boolean', 'description' => 'Only tasks assigned to the user.'],
            'overdue' => ['type' => 'boolean', 'description' => 'Only open tasks past their due date.'],
            'limit' => $this->limitProp(),
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $query = Task::query()->visibleTo($user)->with(['project:id,docket_number', 'assignee:id,name']);
        if ($s = $this->str($args, 'status')) {
            $query->where('status', $s);
        } else {
            $query->where('status', '!=', 'Completed');
        }
        if ($p = $this->str($args, 'priority')) {
            $query->where('priority', $p);
        }
        if (! empty($args['mine'])) {
            $query->where('assignee_id', $user->id);
        }
        if (! empty($args['overdue'])) {
            $query->where('status', '!=', 'Completed')->whereNotNull('due_date')->whereDate('due_date', '<', now());
        }
        $total = (clone $query)->count();
        $rows = $query->orderByRaw('due_date IS NULL')->orderBy('due_date')->limit($this->limit($args))->get()->map(fn (Task $t) => [
            'title' => $t->title, 'docket_number' => $t->project?->docket_number, 'assignee' => $t->assignee?->name,
            'priority' => $t->priority, 'due_date' => self::date($t->due_date), 'status' => $t->status,
        ])->all();

        return ['total' => $total, 'returned' => count($rows), 'truncated' => $total > count($rows), 'tasks' => $rows];
    }
}
