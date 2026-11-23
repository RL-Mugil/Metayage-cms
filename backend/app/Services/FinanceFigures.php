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

    /** Amount collected on invoices dated in the given month (INR). */
    public function collectedInMonth(User $user, Carbon $month, ?int $clientId = null): float
    {
        $register = (float) $this->registerInvoices($user, $clientId)
            ->whereYear('invoice_date', $month->year)->whereMonth('invoice_date', $month->month)
            ->sum(\DB::raw('GREATEST('.self::TOTAL_INR.' - '.self::BALANCE_INR.', 0)'));
        $legacy = (float) $this->legacyInvoices($user, $clientId)->where('status', 'Paid')
            ->whereYear('updated_at', $month->year)->whereMonth('updated_at', $month->month)->sum('total_amount');

        return round($register + $legacy, 2);
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
}
