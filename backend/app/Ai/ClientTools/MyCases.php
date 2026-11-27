<?php

namespace App\Ai\ClientTools;

use App\Models\User;

class MyCases extends ClientTool
{
    public function name(): string { return 'my_cases'; }

    public function title(): string { return 'My cases'; }

    public function description(): string
    {
        return 'The client\'s own cases (patents, trademarks, designs): docket number, title, type, patent office, status, application number and next hard deadline. Optional search text and status filter.';
    }

    protected function permission(): string { return 'cases'; }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'search' => ['type' => 'string', 'description' => 'Part of the docket number, title or application number.'],
            'open_only' => ['type' => 'boolean', 'description' => 'Only cases still in progress (default false).'],
            'limit' => $this->limitProp(),
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $query = $this->ownCases($user)->with('patentApplication:id,application_number,legal_status');
        if ($s = $this->str($args, 'search')) {
            $like = $this->like($s);
            $query->where(fn ($q) => $q->where('docket_number', 'ilike', $like)->orWhere('project_name', 'ilike', $like)->orWhere('invention_title', 'ilike', $like)
                ->orWhereHas('patentApplication', fn ($a) => $a->where('application_number', 'ilike', $like)));
        }
        if (! empty($args['open_only'])) {
            $query->whereNotIn('status', self::CLOSED_STATUSES);
        }
        $total = (clone $query)->count();
        $rows = $query->orderByRaw('hard_deadline ASC NULLS LAST')->limit($this->limit($args))->get()
            ->map(fn ($p) => ['docket_number' => $p->docket_number, 'title' => $p->invention_title ?: $p->project_name, 'type' => $p->project_type,
                'office' => $p->patent_office_code, 'status' => $p->status, 'application_number' => $p->patentApplication?->application_number,
                'legal_status' => $p->patentApplication?->legal_status, 'next_hard_deadline' => self::date($p->hard_deadline)]);

        return ['total' => $total, 'returned' => $rows->count(), 'cases' => $rows->all()];
    }
}
