<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\User;
use App\Services\FinanceFigures;
use App\Support\DataAccess;

class RevenueForecast extends Tool
{
    public function name(): string { return 'revenue_forecast'; }

    public function title(): string { return 'Collections forecast and pipeline'; }

    public function description(): string
    {
        return 'Expected collections for the next 3 months (unpaid invoice balances by due date plus upcoming renewal fee estimates) and the billing funnel (quoted → invoiced → paid) with counts and amounts. INR.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) []];
    }

    public function availableTo(User $user): bool
    {
        return DataAccess::canOpenFinance($user);
    }

    public function handle(User $user, array $args): array
    {
        $figures = app(FinanceFigures::class);

        return ['currency' => 'INR', 'forecast_next_3_months' => $figures->forecast($user), 'funnel' => $figures->funnel($user)];
    }
}
