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
        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        } elseif ($user->isClientRole()) {
            $query->whereIn('client_id', Client::query()->visibleToUser($user)->select('id'));
        } elseif ($user->isGalvanizer()) {
            $query->whereHas('project', fn ($q) => $user->applyProjectScope($q));
        }

        return $query;
    }

    private function legacyInvoices(User $user, ?int $clientId = null): Builder
    {
        $query = Invoice::query()->where('status', '!=', 'Draft');
        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        } elseif ($user->isClientRole()) {
            $query->whereHas('client', fn ($q) => $q->visibleToUser($user));
        } elseif ($user->isGalvanizer()) {
            $query->whereHas('project', fn ($q) => $user->applyProjectScope($q));
        }

        return $query;
    }

    /**
     * @return array{billed: float, received: float, outstanding: float, overdue_count: int, draft_count: int, paid_count: int, invoice_count: int}
     */
    public function summary(User $user, ?int $clientId = null): array
    {
        $today = now()->toDateString();
        $register = $this->registerInvoices($user, $clientId)->selectRaw('
            COALESCE(SUM('.self::TOTAL_INR.'), 0) AS billed,
            COALESCE(SUM('.self::BALANCE_INR.'), 0) AS outstanding,
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

        return [
            'billed' => round($billed, 2),
            'received' => round(max(0, $billed - $outstanding), 2),
            'outstanding' => round($outstanding, 2),
            'overdue_count' => (int) $register->overdue_count + (int) $legacy->overdue_count,
            'draft_count' => $drafts,
            'paid_count' => (int) $register->paid_count + (int) $legacy->paid_count,
            'invoice_count' => (int) $register->invoice_count + (int) $legacy->invoice_count,
        ];
    }

    /** Amount received in the given month, by payment date (INR). */
    public function collectedInMonth(User $user, Carbon $month, ?int $clientId = null): float
    {
        return round((float) $this->payments($user, $clientId)
            ->whereYear('payment_date', $month->year)->whereMonth('payment_date', $month->month)
            ->sum('amount_bcy') + $this->legacyPaymentsIn($user, $clientId, $month), 2);
    }

    /** Payments received against the register invoices the user may see. */
    public function payments(User $user, ?int $clientId = null): Builder
    {
        return \App\Models\RegisterPayment::query()
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
                'status' => $outstanding <= 0 ? 'paid' : (($row->due_date && $row->due_date->isPast()) ? 'overdue' : strtolower((string) ($row->zoho_status ?: 'sent'))),
                'currency' => $row->currency ?: 'INR', 'balance_due' => $row->balance_due, 'invoice_amount' => (float) $row->invoice_amount,
            ];
        });
        $legacy = $this->legacyInvoices($user, $clientId)->get()->map(fn (Invoice $invoice) => [
            'id' => $invoice->id, 'source' => 'portal', 'number' => $invoice->invoice_code, 'client_id' => $invoice->client_id,
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
        $query = PatentInvoiceIn::query()->where('type', 'quote')->whereIn('status', ['Sent', 'Accepted'])
            ->where(fn ($q) => $q->whereNull('zoho_status')->orWhereRaw('LOWER(zoho_status) <> ?', ['invoiced']));
        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        } elseif ($user->isClientRole()) {
            $query->whereIn('client_id', Client::query()->visibleToUser($user)->select('id'));
        } elseif ($user->isGalvanizer()) {
            $query->whereHas('project', fn ($q) => $user->applyProjectScope($q));
        }

        return $query;
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
            ->when($user->isGalvanizer(), fn ($q) => $user->applyProjectScope($q))
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
            ->when($user->isGalvanizer(), fn ($q) => $q->whereHas('project', fn ($p) => $user->applyProjectScope($p)))
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
        if ($user->isGalvanizer()) {
            $user->applyProjectScope($query);
        }

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
