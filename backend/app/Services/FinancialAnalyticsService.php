<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\User;
use App\Models\ZohoInvoice;
use App\Models\ZohoOpeningBalance;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FinancialAnalyticsService
{
    public function build(User $user): array
    {
        // Portal figures: the register (portal-raised + imported from Zoho Books) and older
        // invoices, all in INR. The Zoho column below is kept as a reconciliation check.
        $portalRows = app(FinanceFigures::class)->rows($user);
        $portalQuotes = \App\Models\PatentInvoiceIn::query()->where('type', 'quote')->where('status', '!=', 'Cancelled')->get(['id', 'client_id', 'project_id', 'status', 'invoice_uin'])
            ->concat(Quotation::query()->where('currency', 'INR')->get());
        $zohoInvoices = ZohoInvoice::query()->where('zoho_type', 'invoice')->where('currency', 'INR')->get();
        $zohoQuotes = ZohoInvoice::query()->where('zoho_type', 'quote')->where('currency', 'INR')->get();
        $zohoOpeningBalances = ZohoOpeningBalance::query()->where('currency', 'INR')->get();

        if ($user->isClientRole()) {
            $client = \App\Models\Client::forUser($user);
            $clientId = $client?->id ?? 0;
            $portalQuotes = $portalQuotes->where('client_id', $clientId)->values();
            $zohoInvoices = $zohoInvoices->where('client_id', $clientId)->values();
            $zohoQuotes = $zohoQuotes->where('client_id', $clientId)->values();
            $zohoOpeningBalances = $zohoOpeningBalances->where('client_id', $clientId)->values();
        } elseif (! \App\Support\DataAccess::seesAllFinance($user)) {
            // Galvanizer: their circle; other staff: their CM / SCM cases (none for a paralegal off the list).
            $projectIds = $user->isGalvanizer()
                ? Project::query()->where(fn ($q) => $user->applyProjectScope($q))->pluck('id')
                : (\App\Support\DataAccess::canOpenFinance($user) ? collect(\App\Support\DataAccess::managedCaseIds($user)) : collect());
            $portalQuotes = $portalQuotes->whereIn('project_id', $projectIds)->values();
            $zohoInvoices = $zohoInvoices->whereIn('project_id', $projectIds)->values();
            $zohoQuotes = $zohoQuotes->whereIn('project_id', $projectIds)->values();
            $zohoOpeningBalances = $zohoOpeningBalances->whereIn('client_id', Project::whereIn('id', $projectIds)->pluck('client_id'))->values();
        }

        $portalBilled = (float) $portalRows->sum('billed');
        $portalOutstanding = (float) $portalRows->sum('outstanding');
        $portalPaid = max(0, $portalBilled - $portalOutstanding);
        $portalInvoices = $portalRows->map(fn (array $row) => (object) $row);
        $zohoBilled = (float) $zohoInvoices->sum('total');
        $zohoCollected = (float) $zohoInvoices->sum(fn ($i) => max(0, (float) $i->total - (float) ($i->balance ?? $i->total)));
        $zohoOutstanding = (float) $zohoInvoices->sum('balance');
        $zohoOpeningBalance = (float) $zohoOpeningBalances->sum('balance');

        $portalPrefixes = $portalQuotes->filter(fn ($q) => $q->status === 'Accepted')->count();
        $zohoInvoicePrefixes = $zohoInvoices->map(fn ($r) => substr(strtoupper((string) $r->number), 0, 9))->filter()->unique();
        $zohoConverted = $zohoQuotes->filter(fn ($q) => $zohoInvoicePrefixes->contains(substr(strtoupper((string) $q->number), 0, 9)))->count();

        // Unpaid quotes (sent / accepted, not yet invoiced), receivables split and ageing by band.
        $figures = app(FinanceFigures::class);
        $openQuotes = $figures->openQuotesFiltered($user);
        $earmarked = \App\Models\AdvanceQuoteLink::whereIn('patent_invoice_in_id', $openQuotes->pluck('id'))->selectRaw('patent_invoice_in_id, SUM(amount) s')
            ->groupBy('patent_invoice_in_id')->pluck('s', 'patent_invoice_in_id');
        $quoteDue = fn ($q) => max(0, (float) ($q->total_bcy ?? (float) $q->invoice_amount * ((float) ($q->exchange_rate ?? 1) ?: 1)) - (float) ($earmarked[$q->id] ?? 0));
        $today = now()->startOfDay();
        $band = function ($date) use ($today): string {
            $date = $date ? Carbon::parse($date) : null;
            if (! $date || $date->gte($today)) {
                return 'not_due';
            }
            $d = (int) $date->diffInDays($today);

            return $d <= 30 ? '1_30' : ($d <= 60 ? '31_60' : ($d <= 90 ? '61_90' : '90_plus'));
        };
        $ageing = [];
        foreach (['not_due' => 'Not due', '1_30' => '1–30', '31_60' => '31–60', '61_90' => '61–90', '90_plus' => '90+'] as $key => $label) {
            $ageing[$key] = ['key' => $key, 'band' => $label, 'invoices' => 0.0, 'quotes' => 0.0];
        }
        foreach ($portalRows->where('outstanding', '>', 0.009) as $row) {
            $ageing[$band($row['due_date'])]['invoices'] += (float) $row['outstanding'];
        }
        foreach ($openQuotes as $q) {
            $ageing[$band($q->invoice_date)]['quotes'] += $quoteDue($q);
        }
        $quotesValue = round((float) $openQuotes->sum($quoteDue), 2);
        $costs = \App\Models\MatterCost::query();
        if (! \App\Support\DataAccess::seesAllFinance($user)) {
            $costs->whereIn('project_id', app(MatterProfitability::class)->projects($user)->select('projects.id'));
        }

        return [
            'currency' => 'INR',
            'generated_at' => now()->toIso8601String(),
            'quotes' => ['unpaid_count' => $openQuotes->count(), 'unpaid_value' => $quotesValue],
            'receivables' => ['invoices' => round($portalOutstanding, 2), 'quotes' => $quotesValue, 'opening' => round($zohoOpeningBalance, 2),
                'total' => round($portalOutstanding + $quotesValue + $zohoOpeningBalance, 2)],
            'ageing' => array_values(array_map(fn ($a) => ['key' => $a['key'], 'band' => $a['band'], 'invoices' => round($a['invoices'], 2), 'quotes' => round($a['quotes'], 2)], $ageing)),
            'costs' => $user->isClientRole() ? null : [
                'unbilled' => round((float) (clone $costs)->where('recoverable', true)->where('needs_review', false)->whereNull('billed_invoice_id')->sum('amount_bcy'), 2),
                'to_review' => (clone $costs)->where('needs_review', true)->count(),
                'unassigned' => (clone $costs)->whereNull('project_id')->count(),
            ],
            'portal' => $this->source($portalInvoices, $portalQuotes, $portalBilled, $portalPaid, $portalOutstanding, $portalPrefixes, $this->monthlyPortal($portalRows, app(FinanceFigures::class)->collectedByMonth($user))),
            'zoho' => $this->source($zohoInvoices, $zohoQuotes, $zohoBilled, $zohoCollected, $zohoOutstanding, $zohoConverted, $this->monthlyZoho($zohoInvoices), $zohoOpeningBalance),
            'comparison' => [
                'portal_invoice_count' => $portalInvoices->count(),
                'zoho_invoice_count' => $zohoInvoices->count(),
                'linked_zoho_invoice_count' => $zohoInvoices->whereNotNull('project_id')->count(),
                'unlinked_zoho_invoice_count' => $zohoInvoices->whereNull('project_id')->count(),
                'portal_billed' => round($portalBilled, 2),
                'zoho_billed' => round($zohoBilled, 2),
                'billed_difference' => round($zohoBilled - $portalBilled, 2),
                'portal_outstanding' => round($portalOutstanding, 2),
                'zoho_outstanding' => round($zohoOutstanding, 2),
                'zoho_opening_balance' => round($zohoOpeningBalance, 2),
                'zoho_total_receivables' => round($zohoOutstanding + $zohoOpeningBalance, 2),
                'outstanding_difference' => round($zohoOutstanding - $portalOutstanding, 2),
            ],
        ];
    }

    private function source(Collection $invoices, Collection $quotes, float $billed, float $collected, float $outstanding, int $convertedQuotes, array $monthly, float $openingBalance = 0): array
    {
        $count = $invoices->count();
        return [
            'invoice_count' => $count,
            'quote_count' => $quotes->count(),
            'billed' => round($billed, 2),
            'collected' => round($collected, 2),
            'outstanding' => round($outstanding, 2),
            'opening_balance' => round($openingBalance, 2),
            'total_receivables' => round($outstanding + $openingBalance, 2),
            'collection_rate' => $billed > 0 ? round(($collected / $billed) * 100, 1) : 0,
            'overdue_count' => $invoices->filter(fn ($i) => strtolower((string) $i->status) === 'overdue')->count(),
            'converted_quote_count' => $convertedQuotes,
            'conversion_rate' => $quotes->count() > 0 ? round(($convertedQuotes / $quotes->count()) * 100, 1) : 0,
            'monthly' => $monthly,
        ];
    }

    /** Billed per month of the invoice date, collected per month of the payment date (INR). */
    private function monthlyPortal(Collection $rows, array $collected): array
    {
        $months = $this->months();
        foreach ($rows as $row) {
            $key = optional($row['date'])->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['billed'] += (float) $row['billed'];
            }
        }
        foreach ($collected as $key => $amount) {
            if (isset($months[$key])) {
                $months[$key]['collected'] += $amount;
            }
        }
        return array_values(array_map(fn ($row) => ['month' => $row['month'], 'billed' => round($row['billed'], 2), 'collected' => round($row['collected'], 2)], $months));
    }

    private function monthlyZoho(Collection $invoices): array
    {
        $months = $this->months();
        foreach ($invoices as $invoice) {
            $key = optional($invoice->txn_date)->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['billed'] += (float) $invoice->total;
                $months[$key]['collected'] += max(0, (float) $invoice->total - (float) ($invoice->balance ?? $invoice->total));
            }
        }
        return array_values(array_map(fn ($row) => ['month' => $row['month'], 'billed' => round($row['billed'], 2), 'collected' => round($row['collected'], 2)], $months));
    }

    private function months(): array
    {
        $rows = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i)->startOfMonth();
            $rows[$date->format('Y-m')] = ['month' => $date->format('M Y'), 'billed' => 0.0, 'collected' => 0.0];
        }
        return $rows;
    }
}
