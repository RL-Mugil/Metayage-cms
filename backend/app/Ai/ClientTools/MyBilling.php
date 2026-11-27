<?php

namespace App\Ai\ClientTools;

use App\Http\Controllers\ClientBillingController;
use App\Models\User;

class MyBilling extends ClientTool
{
    public function name(): string { return 'my_billing'; }

    public function title(): string { return 'My billing'; }

    public function description(): string
    {
        return 'What the client owes (INR): total payable (unpaid invoices + unpaid proformas − balance credit), unpaid invoices with due dates and days late, proformas to pay, payments received to date.';
    }

    protected function permission(): string { return 'billing'; }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function handle(User $user, array $args): array
    {
        $data = ClientBillingController::build($this->ownClient($user), $user);
        $s = $data['summary'];

        return [
            'total_payable' => (float) $s['total_receivable'], 'unpaid_invoices' => (float) $s['unpaid_invoices'], 'unpaid_invoice_count' => $s['unpaid_count'],
            'proformas_due' => (float) $s['quoted'], 'overdue' => (float) $s['overdue'], 'paid_to_date' => (float) $s['received'],
            'balance_credit' => max(0, -(float) $s['balance']),
            'invoices' => collect($data['unpaid'])->map(fn ($i) => array_intersect_key($i, array_flip(['number', 'docket', 'date', 'due_date', 'days_overdue', 'amount', 'balance', 'currency'])))->all(),
            'proformas' => collect($data['proformas'])->map(fn ($q) => array_intersect_key($q, array_flip(['number', 'date', 'status', 'amount', 'net_due', 'title'])))->all(),
        ];
    }
}
