<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\User;
use App\Services\FinanceFigures;
use App\Support\DataAccess;

class FinanceSummary extends Tool
{
    public function name(): string { return 'finance_summary'; }

    public function title(): string { return 'Finance summary'; }

    public function description(): string
    {
        return 'Billing figures the user may see (all finance, or only their Client Manager / Secondary CM cases): billed, received, outstanding, waived, overdue count, outstanding by age, and billed vs collected for the last 12 months. Amounts are INR.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'client_id' => ['type' => 'integer', 'description' => 'Only this client.'],
        ]];
    }

    public function availableTo(User $user): bool
    {
        return DataAccess::canOpenFinance($user);
    }

    public function handle(User $user, array $args): array
    {
        $figures = app(FinanceFigures::class);
        $clientId = ! empty($args['client_id']) ? (int) $args['client_id'] : null;

        return [
            'currency' => 'INR',
            'scope' => DataAccess::seesAllFinance($user) ? 'all finance' : 'your Client Manager / Secondary CM cases',
            'summary' => $figures->summary($user, $clientId),
            'ageing' => $figures->ageing($user, $clientId),
            'last_12_months' => $figures->monthly($user, $clientId),
        ];
    }
}
