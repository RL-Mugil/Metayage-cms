<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\Client;
use App\Models\User;
use App\Services\FinanceFigures;
use App\Support\DataAccess;

class Collections extends Tool
{
    public function name(): string { return 'collections'; }

    public function title(): string { return 'Collections (payments received)'; }

    public function description(): string
    {
        return 'Payments received against the invoices the user may see, between two dates (default: this month), grouped by month, client or payment mode, plus the latest receipts. Amounts INR.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'from_date' => ['type' => 'string', 'format' => 'date'],
            'to_date' => ['type' => 'string', 'format' => 'date'],
            'group_by' => ['type' => 'string', 'enum' => ['month', 'client', 'mode']],
            'client_id' => ['type' => 'integer'],
            'limit' => $this->limitProp(),
        ]];
    }

    public function availableTo(User $user): bool
    {
        return DataAccess::canOpenFinance($user);
    }

    public function handle(User $user, array $args): array
    {
        $from = $this->str($args, 'from_date') ?? now()->startOfMonth()->toDateString();
        $to = $this->str($args, 'to_date') ?? now()->toDateString();
        $clientId = ! empty($args['client_id']) ? (int) $args['client_id'] : null;
        $payments = app(FinanceFigures::class)->payments($user, $clientId)->whereBetween('payment_date', [$from, $to])
            ->with('invoice:id,invoice_uin,docket_number')->orderByDesc('payment_date')
            ->get(['id', 'patent_invoice_in_id', 'client_id', 'payment_date', 'amount', 'currency', 'amount_bcy', 'payment_mode', 'reference']);
        $clients = Client::whereIn('id', $payments->pluck('client_id')->filter()->unique())->get(['id', 'client_code', 'legal_name', 'company_name'])->keyBy('id');
        $label = fn ($id) => isset($clients[$id]) ? $clients[$id]->client_code.' · '.($clients[$id]->legal_name ?: $clients[$id]->company_name) : '(unknown)';

        $group = $args['group_by'] ?? 'month';
        $groups = $payments->groupBy(fn ($p) => match ($group) {
            'client' => $label($p->client_id),
            'mode' => $p->payment_mode ?: '(not recorded)',
            default => $p->payment_date?->format('Y-m'),
        })->map(fn ($g, $k) => ['group' => (string) $k, 'amount' => round((float) $g->sum('amount_bcy'), 2), 'payments' => $g->count()])
            ->sortByDesc($group === 'month' ? 'group' : 'amount')->values();

        return [
            'from' => $from, 'to' => $to, 'currency' => 'INR', 'total_received' => round((float) $payments->sum('amount_bcy'), 2), 'payments' => $payments->count(),
            'groups' => $groups->all(),
            'latest' => $payments->take($this->limit($args, 15))->map(fn ($p) => [
                'date' => self::date($p->payment_date), 'client' => $label($p->client_id), 'invoice' => $p->invoice?->invoice_uin,
                'amount' => (float) $p->amount, 'currency' => $p->currency, 'amount_inr' => (float) $p->amount_bcy, 'mode' => $p->payment_mode, 'reference' => $p->reference,
            ])->values()->all(),
            'note' => 'Register payments only; older portal-only invoices are in finance_summary.',
        ];
    }
}
