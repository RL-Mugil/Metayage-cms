<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\Project;
use App\Models\User;

class SearchCases extends Tool
{
    public function name(): string { return 'search_cases'; }

    public function title(): string { return 'Search cases'; }

    public function description(): string
    {
        return 'Find IP cases (matters) the user can see. Filter by text (docket number, title, application number, client name or code), status, service code (PRV, CMP, PCT, FER…), patent office (IN, US, EP, WO…), client, open-only, or a hard deadline before a date. Returns docket, title, client, status, current stage, deadline and team.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string', 'description' => 'Docket number, title words, application number, or client name/code.'],
            'status' => ['type' => 'string'],
            'service_code' => ['type' => 'string', 'description' => 'e.g. PRV, CMP, PCT, FER, PAS'],
            'office' => ['type' => 'string', 'description' => 'Patent office code, e.g. IN, US, EP, WO'],
            'client_id' => ['type' => 'integer'],
            'open_only' => ['type' => 'boolean', 'description' => 'Exclude completed / granted / closed cases.'],
            'deadline_before' => ['type' => 'string', 'format' => 'date', 'description' => 'Only cases with a hard deadline on or before this date (YYYY-MM-DD).'],
            'limit' => $this->limitProp(),
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $query = $this->cases($user)->with([
            'client:id,client_code,legal_name,company_name', 'manager:id,name', 'secondaryManager:id,name', 'patentEngineer:id,name',
            'stages' => fn ($s) => $s->where('status', 'In Progress')->select('id', 'project_id', 'stage_name'),
        ]);
        if ($q = $this->str($args, 'query')) {
            $like = $this->like($q);
            $query->where(fn ($w) => $w->where('docket_number', 'ilike', $like)->orWhere('project_name', 'ilike', $like)
                ->orWhere('application_number', 'ilike', $like)->orWhere('invention_title', 'ilike', $like)
                ->orWhereHas('client', fn ($c) => $c->where('client_code', 'ilike', $like)->orWhere('legal_name', 'ilike', $like)->orWhere('company_name', 'ilike', $like)));
        }
        foreach (['status' => 'status', 'service_code' => 'service_code', 'office' => 'patent_office_code'] as $arg => $col) {
            if ($v = $this->str($args, $arg)) {
                $query->where($col, 'ilike', $v);
            }
        }
        if (! empty($args['client_id'])) {
            $query->where('client_id', (int) $args['client_id']);
        }
        if (! empty($args['open_only'])) {
            $query->whereNotIn('status', self::CLOSED_STATUSES);
        }
        if ($d = $this->str($args, 'deadline_before')) {
            $query->whereNotNull('hard_deadline')->whereDate('hard_deadline', '<=', $d);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByRaw('hard_deadline IS NULL')->orderBy('hard_deadline')->orderByDesc('id')
            ->limit($this->limit($args))->get()->map(fn (Project $p) => self::row($p))->all();

        return ['total' => $total, 'returned' => count($rows), 'truncated' => $total > count($rows), 'cases' => $rows];
    }

    /** @return array<string, mixed> */
    public static function row(Project $p): array
    {
        return [
            'id' => $p->id, 'docket_number' => $p->docket_number, 'title' => $p->project_name,
            'client' => $p->client ? $p->client->client_code.' · '.($p->client->legal_name ?: $p->client->company_name) : null,
            'service_code' => $p->service_code, 'office' => $p->patent_office_code, 'status' => $p->status,
            'current_stage' => $p->relationLoaded('stages') ? $p->stages->first()?->stage_name : null,
            'application_number' => $p->application_number, 'filing_date' => self::date($p->filing_date),
            'hard_deadline' => self::date($p->hard_deadline), 'urgency' => $p->urgency,
            'client_manager' => $p->manager?->name, 'secondary_cm' => $p->secondaryManager?->name, 'patent_engineer' => $p->patentEngineer?->name,
        ];
    }
}
