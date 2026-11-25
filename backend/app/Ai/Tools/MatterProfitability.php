<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\User;
use App\Services\MatterProfitability as Profitability;
use App\Support\DataAccess;

class MatterProfitability extends Tool
{
    public function name(): string { return 'matter_profitability'; }

    public function title(): string { return 'Matter profitability'; }

    public function description(): string
    {
        return 'Margin per matter (INR): fees billed without GST, less Patent Office fees paid, foreign associate and other costs, and staff time at '
            .'internal cost rates; or rolled up by client, service, office or Client Manager. Also official fees paid but not billed. '
            .'Within the matters whose finance the user may see.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'by' => ['type' => 'string', 'enum' => ['matter', 'client', 'service', 'office', 'manager'], 'description' => 'Grouping (default matter).'],
            'from' => ['type' => 'string', 'description' => 'Only invoices, costs and time from this date (YYYY-MM-DD).'],
            'to' => ['type' => 'string', 'description' => 'Up to this date (YYYY-MM-DD).'],
            'lowest_first' => ['type' => 'boolean', 'description' => 'Sort by margin %, lowest first (default: highest billing first).'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ROWS],
        ]];
    }

    public function availableTo(User $user): bool
    {
        return DataAccess::canOpenFinance($user);
    }

    public function handle(User $user, array $args): array
    {
        $service = app(Profitability::class);
        $f = array_filter(['from' => $args['from'] ?? null, 'to' => $args['to'] ?? null]);
        $rows = $service->grouped($service->rows($user, $f), $args['by'] ?? 'matter');
        if (! empty($args['lowest_first'])) {
            $rows = $rows->sortBy(fn ($r) => $r['margin_pct'] ?? INF)->values();
        }

        return ['currency' => 'INR', 'grouped_by' => $args['by'] ?? 'matter', 'count' => $rows->count(),
            'rows' => $rows->take($this->limit($args, 25))->values()->all()];
    }
}
