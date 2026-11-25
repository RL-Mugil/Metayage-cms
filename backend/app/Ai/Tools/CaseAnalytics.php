<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\Client;
use App\Models\ProjectStage;
use App\Models\User;

class CaseAnalytics extends Tool
{
    private const GROUPS = ['status', 'stage', 'service_code', 'office', 'month_opened', 'client_manager', 'client'];

    public function name(): string { return 'case_analytics'; }

    public function title(): string { return 'Case analytics'; }

    public function description(): string
    {
        return 'Counts of the user\'s cases grouped by status, current workflow stage, service code, patent office, month opened, Client Manager or client, with totals (all, open, overdue hard deadlines). Optional filters: open_only, service_code, office, opened_from / opened_to.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['group_by'], 'properties' => [
            'group_by' => ['type' => 'string', 'enum' => self::GROUPS],
            'open_only' => ['type' => 'boolean'],
            'service_code' => ['type' => 'string'],
            'office' => ['type' => 'string'],
            'opened_from' => ['type' => 'string', 'format' => 'date'],
            'opened_to' => ['type' => 'string', 'format' => 'date'],
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $group = (string) ($args['group_by'] ?? 'status');
        if (! in_array($group, self::GROUPS, true)) {
            throw new \InvalidArgumentException('group_by must be one of: '.implode(', ', self::GROUPS));
        }
        $base = $this->cases($user);
        if (! empty($args['open_only'])) {
            $base->whereNotIn('status', self::CLOSED_STATUSES);
        }
        if ($v = $this->str($args, 'service_code')) {
            $base->where('service_code', 'ilike', $v);
        }
        if ($v = $this->str($args, 'office')) {
            $base->where('patent_office_code', 'ilike', $v);
        }
        if ($v = $this->str($args, 'opened_from')) {
            $base->whereDate('projects.created_at', '>=', $v);
        }
        if ($v = $this->str($args, 'opened_to')) {
            $base->whereDate('projects.created_at', '<=', $v);
        }

        $totals = [
            'cases' => (clone $base)->count(),
            'open' => (clone $base)->whereNotIn('status', self::CLOSED_STATUSES)->count(),
            'overdue_hard_deadlines' => (clone $base)->whereNotIn('status', self::CLOSED_STATUSES)->whereNotNull('hard_deadline')->whereDate('hard_deadline', '<', now())->count(),
        ];

        $groups = match ($group) {
            'stage' => ProjectStage::query()->where('status', 'In Progress')->whereIn('project_id', (clone $base)->select('projects.id'))
                ->selectRaw('stage_name AS label, COUNT(DISTINCT project_id) AS count')->groupBy('stage_name')->orderByDesc('count')->get(),
            'month_opened' => (clone $base)->selectRaw("to_char(projects.created_at, 'YYYY-MM') AS label, COUNT(*) AS count")
                ->groupBy('label')->orderBy('label')->get(),
            'client_manager' => $this->named((clone $base)->selectRaw('assigned_manager_id AS gid, COUNT(*) AS count')->groupBy('assigned_manager_id')->get(),
                fn ($ids) => User::whereIn('id', $ids)->pluck('name', 'id')),
            'client' => $this->named((clone $base)->selectRaw('client_id AS gid, COUNT(*) AS count')->groupBy('client_id')->orderByDesc('count')->limit(self::MAX_ROWS)->get(),
                fn ($ids) => Client::whereIn('id', $ids)->get(['id', 'client_code', 'legal_name', 'company_name'])
                    ->mapWithKeys(fn ($c) => [$c->id => $c->client_code.' · '.($c->legal_name ?: $c->company_name)])),
            default => (clone $base)->selectRaw('COALESCE('.['status' => 'status', 'service_code' => 'service_code', 'office' => 'patent_office_code'][$group]."::text, '(none)') AS label, COUNT(*) AS count")
                ->groupBy('label')->orderByDesc('count')->get(),
        };

        return ['group_by' => $group, 'totals' => $totals,
            'groups' => $groups->map(fn ($r) => ['label' => (string) $r->label, 'count' => (int) $r->count])
                ->when($group !== 'month_opened', fn ($c) => $c->sortByDesc('count'))->values()->all()];
    }

    /** Rows of (gid, count) labelled by a name lookup; a null id is "(none)". */
    private function named($rows, \Closure $names)
    {
        $map = $names($rows->pluck('gid')->filter()->all());

        return $rows->map(fn ($r) => (object) ['label' => $r->gid ? ($map[$r->gid] ?? "#{$r->gid}") : '(none)', 'count' => (int) $r->count]);
    }
}
