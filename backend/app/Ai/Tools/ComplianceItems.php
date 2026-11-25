<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\User;
use App\Services\ComplianceScope;

class ComplianceItems extends Tool
{
    public function name(): string { return 'compliance_items'; }

    public function title(): string { return 'Compliance items'; }

    public function description(): string
    {
        return 'Open compliance items (IP deadlines tracked on the Compliance page) the user can see, soonest first, optionally limited to the next N days or one jurisdiction.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 730, 'description' => 'Only items due within N days (overdue included).'],
            'jurisdiction' => ['type' => 'string', 'description' => 'e.g. IN, US, EP'],
            'include_resolved' => ['type' => 'boolean'],
            'limit' => $this->limitProp(),
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $query = app(ComplianceScope::class)->query($user)->with(['project:id,docket_number', 'client:id,client_code,legal_name,company_name']);
        if (empty($args['include_resolved'])) {
            $query->whereNull('resolved_at');
        }
        if (! empty($args['days'])) {
            $query->whereDate('deadline', '<=', now()->addDays(max(1, min(730, (int) $args['days']))));
        }
        if ($j = $this->str($args, 'jurisdiction')) {
            $query->where('jurisdiction', $j);
        }
        $total = (clone $query)->count();
        $today = now()->toDateString();
        $rows = $query->orderBy('deadline')->limit($this->limit($args))->get()->map(fn ($c) => [
            'matter' => $c->matter, 'type' => $c->type, 'jurisdiction' => $c->jurisdiction, 'deadline' => self::date($c->deadline),
            'overdue' => self::date($c->deadline) !== null && self::date($c->deadline) < $today,
            'action_required' => $c->action_required, 'status' => $c->status, 'assignee' => $c->assignee,
            'docket_number' => $c->project?->docket_number, 'client' => $c->client?->client_code,
        ])->all();

        return ['total' => $total, 'returned' => count($rows), 'truncated' => $total > count($rows), 'items' => $rows];
    }
}
