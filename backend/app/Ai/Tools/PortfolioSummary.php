<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\User;

class PortfolioSummary extends Tool
{
    public function name(): string { return 'portfolio_summary'; }

    public function title(): string { return 'IP portfolio summary'; }

    public function description(): string
    {
        return 'The IP portfolio within the user\'s cases: counts by patent office and by matter type, each split into granted, pending (in progress), refused / abandoned and other; plus totals and filings by year.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'client_id' => ['type' => 'integer'],
            'project_type' => ['type' => 'string', 'description' => 'e.g. Patent, Trademark, Design, Copyright'],
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $base = $this->cases($user);
        if (! empty($args['client_id'])) {
            $base->where('client_id', (int) $args['client_id']);
        }
        if ($t = $this->str($args, 'project_type')) {
            $base->where('project_type', 'ilike', $t);
        }
        $rows = $base->get(['id', 'patent_office_code', 'project_type', 'status', 'filing_date']);
        $bucket = fn (?string $s) => match (true) {
            $s === 'Granted' => 'granted',
            in_array($s, ['Refused', 'Abandoned', 'Withdrawn', 'Lapsed', 'Expired'], true) => 'refused_or_abandoned',
            in_array($s, ['Completed', 'Closed', 'Archived'], true) => 'closed',
            default => 'pending',
        };
        $split = fn ($group) => $group->groupBy(fn ($p) => $bucket($p->status))->map->count()->all() + ['total' => $group->count()];

        return [
            'totals' => $split($rows),
            'by_office' => $rows->groupBy(fn ($p) => $p->patent_office_code ?: '(none)')->map($split)->sortByDesc('total')->all(),
            'by_type' => $rows->groupBy(fn ($p) => $p->project_type ?: '(none)')->map($split)->sortByDesc('total')->all(),
            'filed_by_year' => $rows->filter(fn ($p) => $p->filing_date)->groupBy(fn ($p) => substr((string) self::date($p->filing_date), 0, 4))->map->count()->sortKeys()->all(),
        ];
    }
}
