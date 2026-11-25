<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\User;
use App\Services\FinanceFigures;
use App\Support\DataAccess;

class UnbilledAndQuoted extends Tool
{
    public function name(): string { return 'unbilled_and_quoted'; }

    public function title(): string { return 'Unbilled work and open quotes'; }

    public function description(): string
    {
        return 'Revenue not yet invoiced: (1) quotes sent or accepted that were never invoiced, with amounts, and (2) cases that are filed or granted but have no invoice. Scoped to what the user may see.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['limit' => $this->limitProp()]];
    }

    public function availableTo(User $user): bool
    {
        return DataAccess::canOpenFinance($user);
    }

    public function handle(User $user, array $args): array
    {
        $figures = app(FinanceFigures::class);
        $quotes = $figures->quotedNotInvoiced($user)->with('client:id,client_code,legal_name,company_name');
        $count = (clone $quotes)->count();
        $sum = round((float) (clone $quotes)->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(total_bcy, invoice_amount)')), 2);
        $limit = $this->limit($args, 20);

        return [
            'open_quotes' => [
                'count' => $count, 'total_inr' => $sum,
                'items' => $quotes->orderByDesc('invoice_date')->limit($limit)->get()->map(fn ($q) => [
                    'quote' => $q->invoice_uin, 'date' => self::date($q->invoice_date), 'status' => $q->status, 'docket_number' => $q->docket_number,
                    'client' => $q->client ? $q->client->client_code.' · '.($q->client->legal_name ?: $q->client->company_name) : $q->client_name,
                    'amount' => (float) $q->invoice_amount, 'currency' => $q->currency ?: 'INR',
                ])->all(),
            ],
            'cases_not_invoiced' => $figures->unbilled($user, $limit),
        ];
    }
}
