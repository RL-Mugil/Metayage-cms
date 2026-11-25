<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\PatentInvoiceIn;
use App\Models\User;
use App\Support\DataAccess;

class PendingPayments extends Tool
{
    public function name(): string { return 'pending_payments'; }

    public function title(): string { return 'Pending payments'; }

    public function description(): string
    {
        return 'The Pending Payments list: unpaid register invoices (portal and Zoho) the user may see, most overdue first, with client contact, days overdue, balance, follow-up note and waiver status. '
            .'Filters: overdue only, client, ageing band, waivers awaiting a decision, text search. Totals are per currency.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'overdue_only' => ['type' => 'boolean'],
            'client_id' => ['type' => 'integer'],
            'age' => ['type' => 'string', 'enum' => ['not_due', '1_30', '31_60', '61_90', '90_plus']],
            'waivers_only' => ['type' => 'boolean', 'description' => 'Only invoices with a waiver request waiting for a decision.'],
            'search' => ['type' => 'string', 'description' => 'Invoice number, docket, client name or code.'],
            'limit' => $this->limitProp(),
        ]];
    }

    public function availableTo(User $user): bool
    {
        return DataAccess::canOpenFinance($user);
    }

    public function handle(User $user, array $args): array
    {
        $today = now()->startOfDay();
        $query = PatentInvoiceIn::with(['client:id,client_code,legal_name,company_name,contact_name,contact_email,phone', 'waiverRequestedBy:id,name'])
            ->whereNotNull('payment_status')->whereNotIn('status', ['Draft', 'Cancelled'])
            ->where('payment_status', '!=', 'Confirmed')->where(fn ($q) => $q->whereNull('balance_due')->orWhere('balance_due', '>', 0));
        DataAccess::scopeFinance($query, $user);

        if (! empty($args['overdue_only'])) {
            $query->whereNotNull('due_date')->where('due_date', '<', $today->toDateString());
        }
        if (! empty($args['client_id'])) {
            $query->where('client_id', (int) $args['client_id']);
        }
        if (! empty($args['waivers_only'])) {
            $query->where('settlement', 'waiver_requested');
        }
        if ($s = $this->str($args, 'search')) {
            $like = $this->like($s);
            $query->where(fn ($q) => $q->where('invoice_uin', 'ilike', $like)->orWhere('client_name', 'ilike', $like)->orWhere('docket_number', 'ilike', $like)
                ->orWhereHas('client', fn ($c) => $c->where('client_code', 'ilike', $like)->orWhere('legal_name', 'ilike', $like)->orWhere('company_name', 'ilike', $like)));
        }
        $ago = fn (int $d) => $today->copy()->subDays($d)->toDateString();
        match ($args['age'] ?? '') {
            'not_due' => $query->where(fn ($q) => $q->whereNull('due_date')->orWhere('due_date', '>=', $today->toDateString())),
            '1_30' => $query->where('due_date', '<', $today->toDateString())->where('due_date', '>=', $ago(30)),
            '31_60' => $query->where('due_date', '<', $ago(30))->where('due_date', '>=', $ago(60)),
            '61_90' => $query->where('due_date', '<', $ago(60))->where('due_date', '>=', $ago(90)),
            '90_plus' => $query->where('due_date', '<', $ago(90)),
            default => null,
        };

        $totals = (clone $query)->reorder()->select('currency')->selectRaw('COALESCE(SUM(COALESCE(balance_due, invoice_amount)), 0) AS amount')
            ->groupBy('currency')->pluck('amount', 'currency')->map(fn ($a) => round((float) $a, 2));
        $count = (clone $query)->count();
        $overdue = (clone $query)->whereNotNull('due_date')->where('due_date', '<', $today->toDateString())->count();
        $rows = $query->orderByRaw('due_date ASC NULLS LAST')->orderByDesc('invoice_date')->limit($this->limit($args, 25))->get()
            ->map(fn (PatentInvoiceIn $r) => [
                'invoice' => $r->invoice_uin, 'docket_number' => $r->docket_number,
                'client' => $r->client ? $r->client->client_code.' · '.($r->client->legal_name ?: $r->client->company_name) : $r->client_name,
                'contact' => $r->client?->contact_name, 'email' => $r->client?->contact_email,
                'invoice_date' => self::date($r->invoice_date), 'due_date' => self::date($r->due_date),
                'days_overdue' => $r->due_date && $r->due_date->lt($today) ? (int) $r->due_date->diffInDays($today) : 0,
                'currency' => $r->currency ?: 'INR', 'billed' => (float) $r->invoice_amount, 'balance' => (float) ($r->balance_due ?? $r->invoice_amount),
                'payment_status' => $r->payment_status, 'follow_up_note' => $r->status_note, 'follow_up_at' => self::date($r->status_note_at),
                'waiver' => $r->settlement === 'waiver_requested' ? ['requested_amount' => (float) $r->waiver_requested_amount, 'by' => $r->waiverRequestedBy?->name, 'reason' => $r->waiver_reason] : null,
            ])->all();

        return ['pending_count' => $count, 'overdue_count' => $overdue, 'totals_by_currency' => $totals, 'returned' => count($rows), 'truncated' => $count > count($rows), 'invoices' => $rows];
    }
}
