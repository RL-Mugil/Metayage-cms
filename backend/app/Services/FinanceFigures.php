<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\PatentInvoiceIn;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Money figures for every dashboard, from the portal's own records: the Indian Patents
 * (UIN) register (portal-raised rows plus everything imported from Zoho Books) and the older
 * general `invoices` table. Amounts are in INR (Zoho's exchange rate for foreign invoices).
 * A Draft or Cancelled invoice is never counted as billed.
 */
class FinanceFigures
{
    /** INR total and INR balance of a register row, in SQL. */
    private const TOTAL_INR = 'COALESCE(total_bcy, invoice_amount)';
    private const BALANCE_INR = "COALESCE(balance_due * COALESCE(exchange_rate, 1), CASE WHEN payment_status = 'Confirmed' THEN 0 ELSE invoice_amount END)";

    /** Billed register invoices (not drafts, not cancelled), scoped to what the user may see. */
    public function registerInvoices(User $user, ?int $clientId = null): Builder
    {
        return $this->scopedRegister($user, $clientId)->whereNotIn('status', ['Draft', 'Cancelled']);
    }

    /** Register invoices (any status) the user may see. */
    private function scopedRegister(User $user, ?int $clientId = null): Builder
    {
        $query = PatentInvoiceIn::query()->where('type', 'invoice');
        // A client filter narrows the user's own scope; it never widens it.
        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }
        if ($user->isClientRole()) {
            $query->whereIn('client_id', Client::query()->visibleToUser($user)->select('id'));
        } else {
            // Staff: all finance, their circle (galvanizer) or their CM / SCM cases.
            \App\Support\DataAccess::scopeFinance($query, $user);
        }

        return $query;
    }

    private function legacyInvoices(User $user, ?int $clientId = null): Builder
    {
        $query = Invoice::query()->where('status', '!=', 'Draft');
        // A client filter narrows the user's own scope; it never widens it.
        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }
        if ($user->isClientRole()) {
            $query->whereHas('client', fn ($q) => $q->visibleToUser($user));
        } else {
            // Staff: all finance, their circle (galvanizer) or their CM / SCM cases.
            \App\Support\DataAccess::scopeFinance($query, $user);
        }

        return $query;
    }

    /**
     * @return array{billed: float, received: float, outstanding: float, waived: float, overdue_count: int, draft_count: int, paid_count: int, invoice_count: int}
     */
    public function summary(User $user, ?int $clientId = null): array
    {
        $today = now()->toDateString();
        $register = $this->registerInvoices($user, $clientId)->selectRaw('
            COALESCE(SUM('.self::TOTAL_INR.'), 0) AS billed,
            COALESCE(SUM('.self::BALANCE_INR.'), 0) AS outstanding,
            COALESCE(SUM(COALESCE(waived_amount, 0) * COALESCE(NULLIF(exchange_rate, 0), 1)), 0) AS waived,
            SUM(CASE WHEN due_date < ? AND '.self::BALANCE_INR.' > 0 THEN 1 ELSE 0 END) AS overdue_count,
            SUM(CASE WHEN '.self::BALANCE_INR.' <= 0 THEN 1 ELSE 0 END) AS paid_count,
            COUNT(*) AS invoice_count
        ', [$today])->first();
        $drafts = $this->scopedRegister($user, $clientId)->where('status', 'Draft')->count();

        $legacy = $this->legacyInvoices($user, $clientId)->selectRaw("
            COALESCE(SUM(total_amount), 0) AS billed,
            COALESCE(SUM(CASE WHEN status = 'Paid' THEN 0 ELSE balance_due END), 0) AS outstanding,
            SUM(CASE WHEN status = 'Overdue' THEN 1 ELSE 0 END) AS overdue_count,
            SUM(CASE WHEN status = 'Paid' THEN 1 ELSE 0 END) AS paid_count,
            COUNT(*) AS invoice_count
        ")->first();

        $billed = (float) $register->billed + (float) $legacy->billed;
        $outstanding = (float) $register->outstanding + (float) $legacy->outstanding;
        $waived = (float) $register->waived;
        // Quote-first billing: unpaid quotes are money due too (not billed: no tax invoice yet).
        $quoted = round((float) $this->openQuoteReceivables($user, $clientId)->sum('due'), 2);

        return [
            'billed' => round($billed, 2),
            // Waived-off balances are neither received nor outstanding.
            'received' => round(max(0, $billed - $outstanding - $waived), 2),
            'outstanding' => round($outstanding + $quoted, 2),
            'quoted_outstanding' => $quoted,
            'waived' => round($waived, 2),
            'overdue_count' => (int) $register->overdue_count + (int) $legacy->overdue_count,
            'draft_count' => $drafts,
            'paid_count' => (int) $register->paid_count + (int) $legacy->paid_count,
            'invoice_count' => (int) $register->invoice_count + (int) $legacy->invoice_count,
        ];
    }

    /** Register filters the Financial Suite list and its KPI cards share. */
    public const REGISTER_FILTERS = ['search', 'type', 'status', 'source', 'client_id', 'project_id', 'from', 'to'];

    /**
     * Apply the register list's filters (search covers invoice no., docket, client name and code,
     * title and application no.; dates are the document date). The list and the KPI cards use
     * this one function, so they always agree.
     */
    public static function applyRegisterFilters(Builder $q, array $f): Builder
    {
        foreach (['type', 'status', 'source'] as $key) {
            if (($f[$key] ?? '') !== '' && ($f[$key] ?? null) !== null && $f[$key] !== 'all') {
                $q->where('patent_invoices_in.'.$key, $f[$key]);
            }
        }
        if (! empty($f['client_id'])) {
            $q->where('patent_invoices_in.client_id', (int) $f['client_id']);
        }
        if (! empty($f['project_id'])) {
            $q->where('patent_invoices_in.project_id', (int) $f['project_id']);
        }
        if (! empty($f['from'])) {
            $q->whereDate('patent_invoices_in.invoice_date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('patent_invoices_in.invoice_date', '<=', $f['to']);
        }
        $search = trim((string) ($f['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $q->where(fn ($w) => $w->where('patent_invoices_in.docket_number', 'ilike', $like)
                ->orWhere('patent_invoices_in.invoice_uin', 'ilike', $like)
                ->orWhere('patent_invoices_in.zoho_number', 'ilike', $like)
                ->orWhere('patent_invoices_in.client_name', 'ilike', $like)
                ->orWhere('patent_invoices_in.invention_title', 'ilike', $like)
                ->orWhere('patent_invoices_in.patent_office_application_number', 'ilike', $like)
                ->orWhereIn('patent_invoices_in.client_id', Client::query()->where(fn ($c) => $c->where('client_code', 'ilike', $like)
                    ->orWhere('legal_name', 'ilike', $like)->orWhere('company_name', 'ilike', $like))->select('id')));
        }

        return $q;
    }

    /** Whether any register filter is set. */
    public static function hasRegisterFilters(array $f): bool
    {
        foreach (self::REGISTER_FILTERS as $key) {
            if (($f[$key] ?? '') !== '' && ($f[$key] ?? null) !== null && ($f[$key] ?? null) !== 'all') {
                return true;
            }
        }

        return false;
    }

    /**
     * The Financial Suite KPI cards for the register as filtered (search, type, status, dates):
     * invoices billed / received / outstanding / waived / overdue, unpaid quotes, and 12 months
     * billed vs received for the 3D chart. Unfiltered, the cards are the firm-wide summary.
     *
     * @return array<string, mixed>
     */
    public function filteredSummary(User $user, array $f): array
    {
        $type = $f['type'] ?? null;
        $quotes = $this->openQuotesFiltered($user, $f);
        $quoteDue = $this->quoteDue($quotes);
        if (! self::hasRegisterFilters($f)) {
            $s = $this->summary($user);
            $base = ['total_billed' => $s['billed'], 'total_received' => $s['received'], 'total_outstanding' => $s['outstanding'], 'total_waived' => $s['waived'],
                'overdue_count' => $s['overdue_count'], 'draft_count' => $s['draft_count'], 'paid_count' => $s['paid_count'], 'filtered' => false];
        } else {
            $today = now()->toDateString();
            $inv = $type === 'quote' ? null : self::applyRegisterFilters($this->registerInvoices($user), array_diff_key($f, ['type' => 1]))->selectRaw('
                COALESCE(SUM('.self::TOTAL_INR.'), 0) AS billed,
                COALESCE(SUM('.self::BALANCE_INR.'), 0) AS outstanding,
                COALESCE(SUM(COALESCE(waived_amount, 0) * COALESCE(NULLIF(exchange_rate, 0), 1)), 0) AS waived,
                SUM(CASE WHEN due_date < ? AND '.self::BALANCE_INR.' > 0 THEN 1 ELSE 0 END) AS overdue_count,
                SUM(CASE WHEN '.self::BALANCE_INR.' <= 0 THEN 1 ELSE 0 END) AS paid_count', [$today])->first();
            $billed = (float) ($inv->billed ?? 0);
            $outstanding = (float) ($inv->outstanding ?? 0);
            $waived = (float) ($inv->waived ?? 0);
            $base = ['total_billed' => round($billed, 2), 'total_received' => round(max(0, $billed - $outstanding - $waived), 2),
                'total_outstanding' => round($outstanding + (QuoteFirst::enabled() ? $quoteDue : 0), 2), 'total_waived' => round($waived, 2),
                'overdue_count' => (int) ($inv->overdue_count ?? 0), 'draft_count' => 0, 'paid_count' => (int) ($inv->paid_count ?? 0), 'filtered' => true];
        }

        return $base + [
            'quotes_unpaid_count' => $quotes->count(),
            'quotes_unpaid_value' => $quoteDue,
            'monthly' => $this->monthlyFiltered($user, $f),
        ];
    }

    /** Open quotes (sent / accepted, not yet invoiced) the user may see, with the register filters. */
    public function openQuotesFiltered(User $user, array $f = []): Collection
    {
        if (($f['type'] ?? null) === 'invoice') {
            return collect();
        }
        $q = PatentInvoiceIn::query()->openQuotes();
        if ($user->isClientRole()) {
            $q->whereIn('client_id', Client::query()->visibleToUser($user)->select('id'));
        } else {
            \App\Support\DataAccess::scopeFinance($q, $user);
        }

        return self::applyRegisterFilters($q, array_diff_key($f, ['type' => 1, 'status' => 1]))->get(['id', 'total_bcy', 'invoice_amount', 'exchange_rate', 'invoice_date', 'client_id']);
    }

    /** What is still due on these quotes (INR), net of advances earmarked for them. */
    public function quoteDue(Collection $quotes): float
    {
        if ($quotes->isEmpty()) {
            return 0.0;
        }
        $earmarked = \App\Models\AdvanceQuoteLink::whereIn('patent_invoice_in_id', $quotes->pluck('id'))->selectRaw('patent_invoice_in_id, SUM(amount) s')
            ->groupBy('patent_invoice_in_id')->pluck('s', 'patent_invoice_in_id');

        return round((float) $quotes->sum(fn ($q) => max(0, (float) ($q->total_bcy ?? (float) $q->invoice_amount * ((float) ($q->exchange_rate ?? 1) ?: 1)) - (float) ($earmarked[$q->id] ?? 0))), 2);
    }

    /**
     * Last 12 months for the 3D chart: invoiced (by invoice date), received (by payment date)
     * and still outstanding from that month's invoices, all INR, for the filtered register.
     *
     * @return array<int, array{month: string, key: string, billed: float, received: float, outstanding: float, quoted: float}>
     */
    private function monthlyFiltered(User $user, array $f): array
    {
        $from = now()->subMonths(11)->startOfMonth();
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $d = $from->copy()->addMonths($i);
            $months[$d->format('Y-m')] = ['month' => $d->format('M y'), 'key' => $d->format('Y-m'), 'billed' => 0.0, 'received' => 0.0, 'outstanding' => 0.0, 'quoted' => 0.0];
        }
        $filters = array_diff_key($f, ['type' => 1]);
        if (($f['type'] ?? null) !== 'quote') {
            $register = self::applyRegisterFilters($this->registerInvoices($user), $filters);
            $rows = (clone $register)->where('invoice_date', '>=', $from->toDateString())
                ->selectRaw("to_char(invoice_date, 'YYYY-MM') AS ym, COALESCE(SUM(".self::TOTAL_INR."), 0) AS billed, COALESCE(SUM(".self::BALANCE_INR."), 0) AS outstanding")
                ->groupByRaw("to_char(invoice_date, 'YYYY-MM')")->get();
            foreach ($rows as $r) {
                if (isset($months[$r->ym])) {
                    $months[$r->ym]['billed'] = round((float) $r->billed, 2);
                    $months[$r->ym]['outstanding'] = round((float) $r->outstanding, 2);
                }
            }
            $paid = \App\Models\RegisterPayment::query()->cash()->whereIn('patent_invoice_in_id', (clone $register)->select('patent_invoices_in.id'))
                ->where('payment_date', '>=', $from->toDateString())
                ->selectRaw("to_char(payment_date, 'YYYY-MM') AS ym, COALESCE(SUM(amount_bcy), 0) AS amount")->groupByRaw("to_char(payment_date, 'YYYY-MM')")->get();
            foreach ($paid as $r) {
                if (isset($months[$r->ym])) {
                    $months[$r->ym]['received'] = round((float) $r->amount, 2);
                }
            }
        }
        if (($f['type'] ?? null) !== 'invoice') {
            $q = PatentInvoiceIn::query()->openQuotes()->where('invoice_date', '>=', $from->toDateString());
            if ($user->isClientRole()) {
                $q->whereIn('client_id', Client::query()->visibleToUser($user)->select('id'));
            } else {
                \App\Support\DataAccess::scopeFinance($q, $user);
            }
            $quoted = self::applyRegisterFilters($q, array_diff_key($filters, ['status' => 1]))
                ->selectRaw("to_char(invoice_date, 'YYYY-MM') AS ym, COALESCE(SUM(".self::TOTAL_INR."), 0) AS amount")->groupByRaw("to_char(invoice_date, 'YYYY-MM')")->get();
            foreach ($quoted as $r) {
                if (isset($months[$r->ym])) {
                    $months[$r->ym]['quoted'] = round((float) $r->amount, 2);
                }
            }
        }

        return array_values($months);
    }

    /** Amount received in the given month, by payment date (INR). */
    public function collectedInMonth(User $user, Carbon $month, ?int $clientId = null): float
    {
        return round((float) $this->payments($user, $clientId)
            ->whereYear('payment_date', $month->year)->whereMonth('payment_date', $month->month)
            ->sum('amount_bcy') + $this->legacyPaymentsIn($user, $clientId, $month), 2);
    }

    /** Money received (cash only: not TDS withheld or credit notes) against the register invoices the user may see. */
    public function payments(User $user, ?int $clientId = null): Builder
    {
        return \App\Models\RegisterPayment::query()->cash()
            ->whereIn('patent_invoice_in_id', $this->registerInvoices($user, $clientId)->select('id'));
    }

    /**
     * Received per month (INR) for the last 12 months, keyed "Y-m".
     *
     * @return array<string, float>
     */
    public function collectedByMonth(User $user, ?int $clientId = null): array
    {
        $from = now()->subMonths(11)->startOfMonth();
        $out = [];
        foreach ($this->payments($user, $clientId)->where('payment_date', '>=', $from->toDateString())->get(['payment_date', 'amount_bcy']) as $payment) {
            $key = $payment->payment_date->format('Y-m');
            $out[$key] = ($out[$key] ?? 0) + (float) $payment->amount_bcy;
        }
        $legacy = \App\Models\Payment::query()->where('status', 'Completed')->where('payment_date', '>=', $from->toDateString())
            ->whereIn('invoice_id', $this->legacyInvoices($user, $clientId)->select('id'))->get(['payment_date', 'amount']);
        foreach ($legacy as $payment) {
            $key = Carbon::parse($payment->payment_date)->format('Y-m');
            $out[$key] = ($out[$key] ?? 0) + (float) $payment->amount;
        }

        return $out;
    }

    private function legacyPaymentsIn(User $user, ?int $clientId, Carbon $month): float
    {
        return (float) \App\Models\Payment::query()->where('status', 'Completed')
            ->whereIn('invoice_id', $this->legacyInvoices($user, $clientId)->select('id'))
            ->whereYear('payment_date', $month->year)->whereMonth('payment_date', $month->month)->sum('amount');
    }

    /**
     * Each billed invoice as one plain row (INR), for analytics and lists.
     *
     * @return Collection<int, array{id: int, source: string, number: ?string, client_id: ?int, project_id: ?int, date: ?Carbon, due_date: ?Carbon, billed: float, outstanding: float, status: string}>
     */
    public function rows(User $user, ?int $clientId = null): Collection
    {
        $register = $this->registerInvoices($user, $clientId)->get()->map(function (PatentInvoiceIn $row) {
            $billed = (float) ($row->total_bcy ?? $row->invoice_amount);
            $rate = (float) ($row->exchange_rate ?? 1) ?: 1;
            $outstanding = $row->balance_due !== null ? (float) $row->balance_due * $rate : ($row->payment_status === 'Confirmed' ? 0 : $billed);

            return [
                'id' => $row->id, 'source' => $row->source, 'number' => $row->invoice_uin, 'client_id' => $row->client_id,
                'project_id' => $row->project_id, 'date' => $row->invoice_date, 'due_date' => $row->due_date,
                'billed' => $billed, 'outstanding' => $outstanding,
                'status' => $outstanding <= 0 ? ($row->settlement === 'waived' ? 'waived' : 'paid') : (($row->due_date && $row->due_date->isPast()) ? 'overdue' : strtolower((string) ($row->zoho_status ?: 'sent'))),
                'currency' => $row->currency ?: 'INR', 'balance_due' => $row->balance_due, 'invoice_amount' => (float) $row->invoice_amount,
                'waived' => round((float) $row->waived_amount * $rate, 2), 'settlement' => $row->settlement,
            ];
        });
        $legacy = $this->legacyInvoices($user, $clientId)->get()->map(fn (Invoice $invoice) => [
            'id' => $invoice->id, 'legacy' => true, 'source' => 'portal', 'number' => $invoice->invoice_code, 'client_id' => $invoice->client_id,
            'project_id' => $invoice->project_id, 'date' => $invoice->issue_date, 'due_date' => $invoice->due_date,
            'billed' => (float) $invoice->total_amount, 'outstanding' => $invoice->status === 'Paid' ? 0.0 : (float) $invoice->balance_due,
            'status' => strtolower((string) $invoice->status), 'currency' => $invoice->currency,
            'balance_due' => $invoice->balance_due, 'invoice_amount' => (float) $invoice->total_amount,
        ]);

        return $register->concat($legacy)->values();
    }

    /**
     * Outstanding balance by how late it is: current (not yet due), 1-30, 31-60, 61-90, 90+ days.
     *
     * @return array<int, array{key: string, label: string, amount: float, count: int}>
     */
    public function ageing(User $user, ?int $clientId = null): array
    {
        $buckets = [
            'current' => ['label' => 'Not yet due', 'amount' => 0.0, 'count' => 0],
            '1_30' => ['label' => '1–30 days', 'amount' => 0.0, 'count' => 0],
            '31_60' => ['label' => '31–60 days', 'amount' => 0.0, 'count' => 0],
            '61_90' => ['label' => '61–90 days', 'amount' => 0.0, 'count' => 0],
            '90_plus' => ['label' => '90+ days', 'amount' => 0.0, 'count' => 0],
        ];
        $today = now()->startOfDay();
        foreach ($this->rows($user, $clientId) as $row) {
            if ($row['outstanding'] <= 0) {
                continue;
            }
            $late = $row['due_date'] && $row['due_date']->lt($today) ? (int) $row['due_date']->diffInDays($today) : 0;
            $key = match (true) {
                $late <= 0 => 'current',
                $late <= 30 => '1_30',
                $late <= 60 => '31_60',
                $late <= 90 => '61_90',
                default => '90_plus',
            };
            $buckets[$key]['amount'] += $row['outstanding'];
            $buckets[$key]['count']++;
        }
        // Quote-first billing: unpaid quotes age from the quote date.
        foreach ($this->openQuoteReceivables($user, $clientId) as $q) {
            $late = $q['date'] && $q['date']->lt($today) ? (int) $q['date']->diffInDays($today) : 0;
            $key = match (true) { $late <= 0 => 'current', $late <= 30 => '1_30', $late <= 60 => '31_60', $late <= 90 => '61_90', default => '90_plus' };
            $buckets[$key]['amount'] += $q['due'];
            $buckets[$key]['count']++;
        }

        return collect($buckets)->map(fn ($b, $key) => ['key' => $key, 'amount' => round($b['amount'], 2)] + $b)->values()->all();
    }

    /**
     * Billed (by invoice date) and collected (by payment date) for the last 12 months, INR.
     *
     * @return array<int, array{month: string, key: string, billed: float, collected: float}>
     */
    public function monthly(User $user, ?int $clientId = null): array
    {
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->startOfMonth()->subMonths($i);
            $months[$date->format('Y-m')] = ['month' => $date->format('M y'), 'key' => $date->format('Y-m'), 'billed' => 0.0, 'collected' => 0.0, 'prev_billed' => 0.0];
        }
        foreach ($this->rows($user, $clientId) as $row) {
            $key = $row['date']?->format('Y-m');
            if ($key && isset($months[$key])) {
                $months[$key]['billed'] += $row['billed'];
            }
            // The same month a year earlier, for the year-over-year comparison.
            $nextYear = $row['date']?->copy()->addYear()->format('Y-m');
            if ($nextYear && isset($months[$nextYear])) {
                $months[$nextYear]['prev_billed'] += $row['billed'];
            }
        }
        foreach ($this->collectedByMonth($user, $clientId) as $key => $amount) {
            if (isset($months[$key])) {
                $months[$key]['collected'] += $amount;
            }
        }

        return array_values(array_map(fn ($m) => ['billed' => round($m['billed'], 2), 'collected' => round($m['collected'], 2), 'prev_billed' => round($m['prev_billed'], 2)] + $m, $months));
    }

    /** The latest invoices (register and older), newest first. */
    public function recent(User $user, int $limit = 8, ?int $clientId = null): array
    {
        $clients = \App\Models\Client::query()->pluck('legal_name', 'id');
        $today = now()->startOfDay();

        return $this->rows($user, $clientId)
            ->sortByDesc(fn (array $row) => ($row['date']?->timestamp ?? 0) * 1000 + $row['id'])
            ->take($limit)
            ->map(function (array $row) use ($clients, $today) {
                $late = $row['due_date'] && $row['due_date']->lt($today) ? (int) $row['due_date']->diffInDays($today) : 0;
                $dueIn = $row['due_date'] && ! $row['due_date']->lt($today) ? (int) $today->diffInDays($row['due_date']) : null;

                return [
                    'id' => $row['id'], 'source' => $row['source'], 'number' => $row['number'],
                    'client_id' => $row['client_id'], 'client' => $row['client_id'] ? ($clients[$row['client_id']] ?? null) : null,
                    'date' => $row['date']?->toDateString(), 'due_date' => $row['due_date']?->toDateString(),
                    'amount' => round($row['billed'], 2), 'outstanding' => round($row['outstanding'], 2),
                    'state' => $row['outstanding'] <= 0 ? 'Paid' : ($late > 0 ? "{$late} days overdue" : ($dueIn !== null ? "Due in {$dueIn} days" : 'Unpaid')),
                    'overdue' => $row['outstanding'] > 0 && $late > 0,
                ];
            })->values()->all();
    }

    /** The clients owing the most (INR), with how many unpaid invoices. */
    public function topClients(User $user, int $limit = 5): array
    {
        $rows = $this->rows($user)->filter(fn ($row) => $row['outstanding'] > 0 && $row['client_id']);
        $clients = \App\Models\Client::query()->whereIn('id', $rows->pluck('client_id')->unique())->get(['id', 'legal_name', 'company_name', 'client_code'])->keyBy('id');

        return $rows->groupBy('client_id')
            ->map(fn ($group, $id) => [
                'client_id' => (int) $id,
                'name' => $clients->get($id)?->legal_name ?: $clients->get($id)?->company_name ?: '—',
                'client_code' => $clients->get($id)?->client_code,
                'outstanding' => round($group->sum('outstanding'), 2),
                'invoices' => $group->count(),
            ])
            ->sortByDesc('outstanding')->take($limit)->values()->all();
    }

    /**
     * Quotes sent or accepted that have not been turned into an invoice yet (work in the
     * pipeline). A Zoho quote marked "invoiced" or a cancelled one is excluded.
     */
    public function quotedNotInvoiced(User $user, ?int $clientId = null): Builder
    {
        $query = PatentInvoiceIn::query()->openQuotes();
        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }
        if ($user->isClientRole()) {
            $query->whereIn('client_id', Client::query()->visibleToUser($user)->select('id'));
        } else {
            // Staff: all finance, their circle (galvanizer) or their CM / SCM cases.
            \App\Support\DataAccess::scopeFinance($query, $user);
        }

        return $query;
    }

    /**
     * Quote-first billing: open quotes are receivables. Each open quote (clients not exempt) with
     * what is still due on it (INR, less advances earmarked for it), dated by the quote date.
     *
     * @return Collection<int, array{quote: PatentInvoiceIn, due: float, date: ?Carbon}>
     */
    public function openQuoteReceivables(User $user, ?int $clientId = null): Collection
    {
        if (! QuoteFirst::enabled()) {
            return collect();
        }
        $exempt = QuoteFirst::settings()['exempt_client_ids'];
        $quotes = $this->quotedNotInvoiced($user, $clientId)->when($exempt !== [], fn ($q) => $q->whereNotIn('client_id', $exempt))->get();
        $earmarked = \App\Models\AdvanceQuoteLink::whereIn('patent_invoice_in_id', $quotes->pluck('id'))->selectRaw('patent_invoice_in_id, SUM(amount) s')
            ->groupBy('patent_invoice_in_id')->pluck('s', 'patent_invoice_in_id');

        return $quotes->map(fn (PatentInvoiceIn $q) => ['quote' => $q, 'date' => $q->invoice_date,
            'due' => round(max(0, (float) ($q->total_bcy ?? (float) $q->invoice_amount * ((float) ($q->exchange_rate ?? 1) ?: 1)) - (float) ($earmarked[$q->id] ?? 0)), 2)])
            ->filter(fn ($r) => $r['due'] > 0.009)->values();
    }
    /**
     * Expected collections for the next 3 months: unpaid invoice balances by due date (overdue
     * and undated count in the current month) plus upcoming renewal fee estimates.
     *
     * @return array<int, array{month: string, key: string, invoices: float, renewals: float}>
     */
    public function forecast(User $user): array
    {
        $months = [];
        for ($i = 0; $i < 3; $i++) {
            $date = now()->startOfMonth()->addMonths($i);
            $months[$date->format('Y-m')] = ['month' => $date->format('M y'), 'key' => $date->format('Y-m'), 'invoices' => 0.0, 'renewals' => 0.0];
        }
        $current = array_key_first($months);
        foreach ($this->rows($user) as $row) {
            if ($row['outstanding'] <= 0) {
                continue;
            }
            $key = $row['due_date'] && $row['due_date']->gt(now()) ? $row['due_date']->format('Y-m') : $current;
            if (isset($months[$key])) {
                $months[$key]['invoices'] += $row['outstanding'];
            }
        }
        $apps = \App\Models\Project::query()->whereNotNull('patent_application_id')
            ->when($user->isClientRole(), fn ($q) => $q->whereIn('client_id', Client::query()->visibleToUser($user)->select('id')))
            ->when(! $user->isClientRole(), fn ($q) => \App\Support\DataAccess::scopeFinance($q, $user, 'id'))
            ->select('patent_application_id');
        foreach (RenewalOutlook::nextDue($apps, 2000) as $renewal) {
            $key = $renewal['days'] < 0 ? $current : substr((string) $renewal['due_date'], 0, 7);
            if (isset($months[$key]) && $renewal['estimate'] && $renewal['estimate']['currency'] === 'INR') {
                $months[$key]['renewals'] += $renewal['estimate']['amount'];
            }
        }

        return array_values(array_map(fn ($m) => ['invoices' => round($m['invoices'], 2), 'renewals' => round($m['renewals'], 2)] + $m, $months));
    }

    /**
     * Billed (INR) by service code and by patent office, biggest first.
     *
     * @return array{services: array<int, array{code: string, billed: float}>, offices: array<int, array{code: string, billed: float}>}
     */
    public function byServiceAndOffice(User $user): array
    {
        $rows = $this->registerInvoices($user)->get(['service_code', 'patent_office_code', 'total_bcy', 'invoice_amount']);
        $sum = fn (string $field) => $rows->groupBy(fn ($r) => strtoupper((string) ($r->{$field} ?: '—')))
            ->map(fn ($g, $code) => ['code' => (string) $code, 'billed' => round((float) $g->sum(fn ($r) => (float) ($r->total_bcy ?? $r->invoice_amount)), 2)])
            ->sortByDesc('billed')->values();

        return ['services' => $sum('service_code')->take(10)->all(), 'offices' => $sum('patent_office_code')->take(8)->all()];
    }

    /**
     * Quote → invoice → paid funnel: quotes sent, accepted, invoiced, and invoices fully paid.
     *
     * @return array<int, array{stage: string, count: int, amount: float}>
     */
    public function funnel(User $user): array
    {
        $quotes = PatentInvoiceIn::query()->where('type', 'quote')->whereNotIn('status', ['Draft'])
            ->when($user->isClientRole(), fn ($q) => $q->whereIn('client_id', Client::query()->visibleToUser($user)->select('id')))
            ->when(! $user->isClientRole(), fn ($q) => \App\Support\DataAccess::scopeFinance($q, $user))
            ->get(['status', 'zoho_status', 'total_bcy', 'invoice_amount']);
        $amount = fn ($set) => round((float) $set->sum(fn ($q) => (float) ($q->total_bcy ?? $q->invoice_amount)), 2);
        $accepted = $quotes->filter(fn ($q) => $q->status === 'Accepted');
        $invoiced = $quotes->filter(fn ($q) => strtolower((string) $q->zoho_status) === 'invoiced');
        $paid = $this->rows($user)->filter(fn ($r) => $r['outstanding'] <= 0);

        return [
            ['stage' => 'Quotes sent', 'count' => $quotes->count(), 'amount' => $amount($quotes)],
            ['stage' => 'Accepted', 'count' => $accepted->count(), 'amount' => $amount($accepted)],
            ['stage' => 'Invoiced', 'count' => $invoiced->count(), 'amount' => $amount($invoiced)],
            ['stage' => 'Invoices paid', 'count' => $paid->count(), 'amount' => round((float) $paid->sum('billed'), 2)],
        ];
    }

    /**
     * Billing opportunities: cases whose filing step is done (or that are granted) but that have
     * no invoice in the register for the case or its docket.
     *
     * @return array{count: int, items: array<int, array<string, mixed>>}
     */
    public function unbilled(User $user, int $limit = 15): array
    {
        if ($user->isClientRole()) {
            return ['count' => 0, 'items' => []];
        }
        $query = \App\Models\Project::query()->whereNotNull('docket_number')
            ->where(fn ($q) => $q->where('status', 'Granted')
                ->orWhereHas('stages', fn ($st) => $st->where('status', 'Completed')
                    ->where(fn ($n) => $n->where('stage_name', 'ilike', '%filed%')->orWhere('stage_name', 'ilike', '%filing receipt%'))))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('patent_invoices_in')
                ->where('patent_invoices_in.type', 'invoice')->where('patent_invoices_in.status', '!=', 'Cancelled')
                ->where(fn ($w) => $w->whereColumn('patent_invoices_in.project_id', 'projects.id')
                    ->orWhereRaw("UPPER(patent_invoices_in.invoice_uin) LIKE UPPER(projects.docket_number) || '%'")));
        \App\Support\DataAccess::scopeFinance($query, $user, 'projects.id');

        return [
            'count' => (clone $query)->count(),
            'items' => $query->with('client:id,legal_name,company_name')->orderByDesc('updated_at')->limit($limit)
                ->get(['id', 'docket_number', 'project_name', 'service_code', 'status', 'client_id'])
                ->map(fn ($p) => ['id' => $p->id, 'docket_number' => $p->docket_number, 'project_name' => $p->project_name,
                    'service_code' => $p->service_code, 'status' => $p->status,
                    'client' => $p->client?->legal_name ?: $p->client?->company_name])->all(),
        ];
    }
}
