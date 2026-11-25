<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\User;
use App\Services\FinanceFigures;
use App\Support\DataAccess;

class RevenueBreakdown extends Tool
{
    public function name(): string { return 'revenue_breakdown'; }

    public function title(): string { return 'Revenue by service, office or client'; }

    public function description(): string
    {
        return 'Billed amounts (INR) broken down by service code and patent office, plus the top clients by billing, for the invoices the user may see.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'top_clients' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'description' => 'How many top clients (default 10).'],
        ]];
    }

    public function availableTo(User $user): bool
    {
        return DataAccess::canOpenFinance($user);
    }

    public function handle(User $user, array $args): array
    {
        $figures = app(FinanceFigures::class);

        return ['currency' => 'INR'] + $figures->byServiceAndOffice($user)
            + ['top_clients' => $figures->topClients($user, max(1, min(25, (int) ($args['top_clients'] ?? 10))))];
    }
}
