<?php

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use App\Models\User;
use App\Services\FinanceFigures;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Data behind the Payment Collection and Financial Summary reports, from the portal register
 * (single source of truth). Filters: a date range or one exact date (invoice date for
 * invoices, payment date for payments, decision date for waivers) and/or one client.
 * Every money figure is in INR.
 */
class PaymentReportData
{
    public function __construct(private readonly FinanceFigures $figures) {}

    /**
     * @return array{filters: array<string, mixed>, summary: array<string, float|int>, pending: array<int, array<string, mixed>>,
     *   payments: array<int, array<string, mixed>>, waivers: array<int, array<string, mixed>>, invoices: array<int, array<string, mixed>>,
     *   ageing: array<int, array<string, mixed>>, by_client: array<int, array<string, mixed>>, monthly: array<int, array<string, mixed>>}
     */
    public function build(User $user, ?Carbon $from, ?Carbon $to, ?int $clientId): array
    {
        $today = now()->startOfDay();
        $inRange = fn (?Carbon $d) => $d && (! $from || $d->gte($from->copy()->startOfDay())) && (! $to || $d->lte($to->copy()->endOfDay()));
        // With no dates chosen, payments and waivers cover the last 12 months (open invoices are always all of them).
        $periodFrom = $from ?? (! $to ? $today->copy()->subYear()->addDay() : null);
        $inPeriod = fn (?Carbon $d) => $d && (! $periodFrom || $d->gte($periodFrom->copy()->startOfDay())) && (! $to || $d->lte($to->copy()->endOfDay()));

        $invoices = $this->figures->registerInvoices($user, $clientId)
            ->with('client:id,legal_name,company_name,client_code,contact_name,contact_email,phone', 'project:id,docket_number')
            ->get();
        $invoices = $invoices->filter(fn ($i) => (! $from && ! $to) || $inRange($i->invoice_date))->values();
        $clientName = fn ($i) => $i->client?->legal_name ?: $i->client?->company_name ?: $i->client_name ?: '—';

        $rows = $invoices->map(function (PatentInvoiceIn $i) use ($today, $clientName) {
            $rate = (float) ($i->exchange_rate ?? 1) ?: 1.0;
            $billed = (float) ($i->total_bcy ?? $i->invoice_amount);
            $balance = $i->balance_due !== null ? (float) $i->balance_due * $rate : ($i->payment_status === 'Confirmed' ? 0.0 : $billed);
            $waived = (float) $i->waived_amount * $rate;
            $days = $i->due_date && $i->due_date->lt($today) && $balance > 0.009 ? (int) $i->due_date->diffInDays($today) : 0;

            return [
                'id' => $i->id, 'type' => 'Invoice', 'invoice' => $i->invoice_uin, 'client' => $clientName($i), 'client_code' => $i->client?->client_code,
                'contact' => $i->client?->contact_name, 'email' => $i->client?->contact_email, 'phone' => $i->client?->phone,
                'case' => $i->project?->docket_number ?? $i->docket_number, 'title' => $i->invention_title,
                'invoice_date' => $i->invoice_date?->toDateString(), 'due_date' => $i->due_date?->toDateString(),
                'days_overdue' => $days, 'age' => $this->ageBand($i->due_date, $today, $balance),
                'billed' => round($billed, 2), 'received' => round(max(0, $billed - $balance - $waived), 2),
                'waived' => round($waived, 2), 'balance' => round($balance, 2), 'currency' => $i->currency ?: 'INR',
                'status' => $balance <= 0.009 ? ($waived > 0 ? 'Waived' : 'Paid') : ($days > 0 ? 'Overdue' : 'Pending'),
                'follow_up' => $i->status_note, 'settlement' => $i->settlement,
            ];
        });

        // Unpaid quotes (sent / accepted, not yet invoiced): money due too, but not billed (no tax invoice yet).
        // A quote is payable from its date; received = advances earmarked for it and payments on it.
        $quoteQuery = PatentInvoiceIn::query()->openQuotes()->with('client:id,legal_name,company_name,client_code,contact_name,contact_email,phone', 'project:id,docket_number');
        if ($user->isClientRole()) {
            $quoteQuery->whereIn('client_id', Client::query()->visibleToUser($user)->select('id'));
        } else {
            \App\Support\DataAccess::scopeFinance($quoteQuery, $user);
        }
        $quotes = $quoteQuery->when($clientId, fn ($q) => $q->where('client_id', $clientId))->get()
            ->filter(fn ($q) => (! $from && ! $to) || $inRange($q->invoice_date))
            ->map(function (PatentInvoiceIn $q) use ($today, $clientName) {
                $state = \App\Services\QuoteFirst::paidState($q);
                $days = $q->invoice_date && $q->invoice_date->lt($today) && $state['due'] > 0.009 ? (int) $q->invoice_date->diffInDays($today) : 0;

                return [
                    'id' => $q->id, 'type' => 'Quote', 'invoice' => $q->invoice_uin ?: $q->zoho_number, 'client' => $clientName($q), 'client_code' => $q->client?->client_code,
                    'contact' => $q->client?->contact_name, 'email' => $q->client?->contact_email, 'phone' => $q->client?->phone,
                    'case' => $q->project?->docket_number ?? $q->docket_number, 'title' => $q->invention_title,
                    'invoice_date' => $q->invoice_date?->toDateString(), 'due_date' => $q->invoice_date?->toDateString(),
                    'days_overdue' => $days, 'age' => $this->ageBand($q->invoice_date, $today, $state['due']),
                    'billed' => $state['total'], 'received' => $state['received'], 'waived' => 0.0, 'balance' => $state['due'], 'currency' => $q->currency ?: 'INR',
                    'status' => $state['fully_paid'] ? 'Quote · paid, to invoice' : ($state['received'] > 0 ? 'Quote · part paid' : 'Quote · unpaid'),
                    'follow_up' => $q->status_note, 'settlement' => null,
                ];
            })->filter(fn ($r) => $r['balance'] > 0.009)->values();

        // Follow-up: unpaid invoices and unpaid quotes together, most overdue first.
        $pending = $rows->filter(fn ($r) => $r['balance'] > 0.009)->concat($quotes)->sortByDesc('days_overdue')->values();

        $payments = RegisterPayment::query()->cash()
            ->whereIn('patent_invoice_in_id', $this->figures->registerInvoices($user, $clientId)->select('id'))
            ->with('invoice:id,invoice_uin,client_id,client_name', 'invoice.client:id,legal_name,company_name,client_code', 'recordedBy:id,name')
            ->orderByDesc('payment_date')->get()
            ->filter(fn ($p) => $inPeriod($p->payment_date))
            ->map(fn (RegisterPayment $p) => [
                'date' => $p->payment_date?->toDateString(), 'invoice' => $p->invoice?->invoice_uin,
                'client' => $p->invoice?->client?->legal_name ?: $p->invoice?->client?->company_name ?: $p->invoice?->client_name ?: '—',
                'client_code' => $p->invoice?->client?->client_code, 'amount' => round((float) $p->amount_bcy, 2),
                'mode' => $p->estimated ? 'Per Zoho (date estimated)' : ($p->payment_mode ?: '—'), 'reference' => $p->reference,
                'source' => $p->source === 'portal' ? 'Marked in portal'.($p->recordedBy ? ' by '.$p->recordedBy->name : '') : 'Zoho Books',
            ])->values();

        $waivers = $this->figures->registerInvoices($user, $clientId)->where('waived_amount', '>', 0)
            ->with('client:id,legal_name,company_name,client_code', 'settledBy:id,name')->get()
            ->filter(fn ($i) => $inPeriod($i->settled_at))
            ->map(fn (PatentInvoiceIn $i) => [
                'date' => $i->settled_at?->toDateString(), 'invoice' => $i->invoice_uin, 'client' => $clientName($i),
                'client_code' => $i->client?->client_code, 'amount' => round((float) $i->waived_amount * ((float) ($i->exchange_rate ?? 1) ?: 1), 2),
                'reason' => $i->waiver_reason, 'approved_by' => $i->settledBy?->name,
            ])->values();

        $bands = ['not_due' => 'Not yet due', '1_30' => '1–30 days', '31_60' => '31–60 days', '61_90' => '61–90 days', '90_plus' => '90+ days'];
        $ageing = $pending->groupBy('client')->map(function (Collection $g, $client) use ($bands) {
            $row = ['client' => $client, 'client_code' => $g->first()['client_code']];
            foreach ($bands as $key => $label) {
                $row[$label] = round($g->where('age', $key)->sum('balance'), 2);
            }
            $row['Total'] = round($g->sum('balance'), 2);
            $row['Invoices'] = $g->where('type', 'Invoice')->count();
            $row['Quotes'] = $g->where('type', 'Quote')->count();

            return $row;
        })->sortByDesc('Total')->values();

        // Balance credit: money the client has paid that is not yet applied to an invoice (the client
        // ledger's balance credit lines and unapplied credit notes). Deducted from what is due.
        $credits = $this->balanceCredits($user, $clientId, $rows->pluck('client_code')->concat($quotes->pluck('client_code')));
        $quotesByClient = $quotes->groupBy('client');
        $byClient = $rows->concat($quotes)->groupBy('client')->map(function (Collection $g, $client) use ($quotesByClient, $credits) {
            $inv = $g->where('type', 'Invoice');
            $quoted = round((float) ($quotesByClient[$client] ?? collect())->sum('balance'), 2);
            $credit = round((float) ($credits[$g->first()['client_code']]['amount'] ?? 0), 2);

            return [
                'client' => $client, 'client_code' => $g->first()['client_code'], 'invoices' => $inv->count(),
                'billed' => round($inv->sum('billed'), 2), 'received' => round($inv->sum('received'), 2),
                'waived' => round($inv->sum('waived'), 2), 'outstanding' => round($inv->sum('balance'), 2),
                'quotes' => $g->where('type', 'Quote')->count(), 'quotes_due' => $quoted, 'balance_credit' => $credit,
                'total_due' => round($inv->sum('balance') + $quoted - $credit, 2),
            ];
        });
        // Clients holding only a balance credit (nothing due) still show, so the deduction is visible.
        foreach ($credits as $code => $c) {
            if (! $byClient->contains(fn ($r) => $r['client_code'] === $code)) {
                $byClient->put('credit:'.$code, ['client' => $c['client'], 'client_code' => $code, 'invoices' => 0, 'billed' => 0.0, 'received' => 0.0,
                    'waived' => 0.0, 'outstanding' => 0.0, 'quotes' => 0, 'quotes_due' => 0.0, 'balance_credit' => round($c['amount'], 2), 'total_due' => round(-$c['amount'], 2)]);
            }
        }
        $byClient = $byClient->sortByDesc('total_due')->values();
        $balanceCredit = round((float) collect($credits)->sum('amount'), 2);

        $billed = (float) $rows->sum('billed');
        $outstanding = (float) $rows->sum('balance');
        $waived = (float) $rows->sum('waived');
        $received = (float) $rows->sum('received');
        $clientRow = $clientId ? Client::find($clientId, ['id', 'legal_name', 'company_name', 'client_code']) : null;

        return [
            'filters' => [
                'from' => $from?->toDateString(), 'to' => $to?->toDateString(),
                'client' => $clientRow ? ($clientRow->legal_name ?: $clientRow->company_name).' ('.$clientRow->client_code.')' : 'All clients',
                'payments_from' => $periodFrom?->toDateString(),
            ],
            'summary' => [
                'billed' => round($billed, 2), 'received' => round($received, 2), 'outstanding' => round($outstanding, 2),
                'waived' => round($waived, 2), 'collection_rate' => $billed > 0 ? round($received / $billed * 100, 1) : 0.0,
                'invoices' => $rows->count(), 'pending_invoices' => $pending->count(),
                'overdue_invoices' => $pending->where('days_overdue', '>', 0)->count(),
                'overdue_amount' => round((float) $pending->where('days_overdue', '>', 0)->sum('balance'), 2),
                'payments' => $payments->count(), 'payments_amount' => round((float) $payments->sum('amount'), 2),
                // Unpaid quotes (proformas) and the total due (invoices + quotes).
                'quotes' => $quotes->count(), 'quotes_amount' => round((float) $quotes->sum('balance'), 2),
                // Total due = unpaid invoices + unpaid proformas − balance credit (minus: credit with us).
                'balance_credit' => $balanceCredit,
                'total_due' => round($outstanding + (float) $quotes->sum('balance') - $balanceCredit, 2),
            ],
            'ageing_totals' => collect($bands)->map(fn ($label, $key) => ['band' => $label, 'amount' => round((float) $pending->where('age', $key)->sum('balance'), 2), 'count' => $pending->where('age', $key)->count(),
                'invoices' => round((float) $pending->where('age', $key)->where('type', 'Invoice')->sum('balance'), 2), 'quotes' => round((float) $pending->where('age', $key)->where('type', 'Quote')->sum('balance'), 2)])->values()->all(),
            'pending' => $pending->all(),
            'payments' => $payments->all(),
            'waivers' => $waivers->all(),
            'invoices' => $rows->concat($quotes)->sortByDesc('invoice_date')->values()->all(),
            'ageing' => $ageing->all(),
            'by_client' => $byClient->all(),
            'monthly' => $this->figures->monthly($user, $clientId),
        ];
    }

    /**
     * Balance credit per client code (INR): ledger lines of money received and not yet applied
     * (balance credit, unapplied credit notes), for the clients in the report's scope.
     *
     * @return array<string, array{client: string, amount: float}>
     */
    private function balanceCredits(User $user, ?int $clientId, Collection $codesInReport): array
    {
        $clients = Client::query();
        if ($clientId) {
            $clients->whereKey($clientId);
        } elseif ($user->isClientRole()) {
            $clients->visibleToUser($user);
        } elseif (! \App\Support\DataAccess::seesAllFinance($user)) {
            // Scoped staff: only clients already in their report.
            $clients->whereIn('client_code', $codesInReport->filter()->unique()->values()->all() ?: ['']);
        }
        $sums = \App\Models\ClientLedger::query()->whereIn('client_id', $clients->select('id'))
            ->where(fn ($q) => $q->whereIn('document_type', ['Balance credit', 'Advance'])
                ->orWhere(fn ($w) => $w->where('document_type', 'Credit note')->where('document_reference', 'like', '% · balance credit')))
            ->selectRaw('client_id, SUM(credit) AS amount')->groupBy('client_id')->pluck('amount', 'client_id');
        $out = [];
        foreach (Client::whereIn('id', $sums->keys())->get(['id', 'client_code', 'legal_name', 'company_name']) as $c) {
            if ((float) $sums[$c->id] > 0.009) {
                $out[(string) $c->client_code] = ['client' => $c->legal_name ?: $c->company_name ?: $c->client_code, 'amount' => (float) $sums[$c->id]];
            }
        }

        return $out;
    }

    private function ageBand(?Carbon $due, Carbon $today, float $balance): string
    {
        if ($balance <= 0.009 || ! $due || $due->gte($today)) {
            return 'not_due';
        }
        $days = $due->diffInDays($today);

        return match (true) {
            $days <= 30 => '1_30', $days <= 60 => '31_60', $days <= 90 => '61_90', default => '90_plus',
        };
    }
}
