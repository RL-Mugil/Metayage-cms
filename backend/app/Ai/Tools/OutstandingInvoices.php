<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\FinanceFigures;
use App\Support\DataAccess;

class OutstandingInvoices extends Tool
{
    public function name(): string { return 'outstanding_invoices'; }

    public function title(): string { return 'Outstanding invoices'; }

    public function description(): string
    {
        return 'Unpaid invoices the user may see, oldest due date first (or largest first), with client, case, due date, outstanding amount (INR) and status. Optionally overdue only or one client.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'overdue_only' => ['type' => 'boolean'],
            'client_id' => ['type' => 'integer'],
            'sort' => ['type' => 'string', 'enum' => ['due_date', 'amount']],
            'limit' => $this->limitProp(),
        ]];
    }

    public function availableTo(User $user): bool
    {
        return DataAccess::canOpenFinance($user);
    }

    public function handle(User $user, array $args): array
    {
        $clientId = ! empty($args['client_id']) ? (int) $args['client_id'] : null;
        $today = now()->startOfDay();
        $rows = app(FinanceFigures::class)->rows($user, $clientId)->filter(fn ($r) => $r['outstanding'] > 0);
        if (! empty($args['overdue_only'])) {
            $rows = $rows->filter(fn ($r) => $r['due_date'] && $r['due_date']->lt($today));
        }
        $rows = ($args['sort'] ?? 'due_date') === 'amount'
            ? $rows->sortByDesc('outstanding')
            : $rows->sortBy(fn ($r) => $r['due_date'] ? $r['due_date']->format('Y-m-d') : '9999');
        $total = $rows->count();
        $sum = round($rows->sum('outstanding'), 2);
        $page = $rows->take($this->limit($args))->values();

        $clients = Client::whereIn('id', $page->pluck('client_id')->filter())->get(['id', 'client_code', 'legal_name', 'company_name'])->keyBy('id');
        $cases = Project::whereIn('id', $page->pluck('project_id')->filter())->pluck('docket_number', 'id');

        return [
            'currency' => 'INR', 'total_invoices' => $total, 'total_outstanding' => $sum, 'returned' => $page->count(), 'truncated' => $total > $page->count(),
            'invoices' => $page->map(fn ($r) => [
                'number' => $r['number'],
                'client' => isset($clients[$r['client_id']]) ? $clients[$r['client_id']]->client_code.' · '.($clients[$r['client_id']]->legal_name ?: $clients[$r['client_id']]->company_name) : null,
                'docket_number' => $cases[$r['project_id']] ?? null, 'invoice_date' => self::date($r['date']), 'due_date' => self::date($r['due_date']),
                'days_overdue' => $r['due_date'] && $r['due_date']->lt($today) ? (int) $r['due_date']->diffInDays($today) : 0,
                'outstanding' => round($r['outstanding'], 2), 'status' => $r['status'],
            ])->all(),
        ];
    }
}
