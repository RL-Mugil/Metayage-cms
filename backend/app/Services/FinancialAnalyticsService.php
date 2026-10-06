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
        $portalInvoices = Invoice::query()->where('currency', 'INR')->with('payments')->get();
        $portalQuotes = Quotation::query()->where('currency', 'INR')->get();
        $zohoInvoices = ZohoInvoice::query()->where('zoho_type', 'invoice')->where('currency', 'INR')->get();
        $zohoQuotes = ZohoInvoice::query()->where('zoho_type', 'quote')->where('currency', 'INR')->get();
        $zohoOpeningBalances = ZohoOpeningBalance::query()->where('currency', 'INR')->get();

        if ($user->isClientRole()) {
            $client = \App\Models\Client::forUser($user);
            $clientId = $client?->id ?? 0;
            $portalInvoices = $portalInvoices->where('client_id', $clientId)->values();
            $portalQuotes = $portalQuotes->where('client_id', $clientId)->values();
            $zohoInvoices = $zohoInvoices->where('client_id', $clientId)->values();
            $zohoQuotes = $zohoQuotes->where('client_id', $clientId)->values();
            $zohoOpeningBalances = $zohoOpeningBalances->where('client_id', $clientId)->values();
        } elseif ($user->isGalvanizer()) {
            $projectIds = Project::query()->where(fn ($q) => $user->applyProjectScope($q))->pluck('id');
            $portalInvoices = $portalInvoices->whereIn('project_id', $projectIds)->values();
            $portalQuotes = $portalQuotes->whereIn('project_id', $projectIds)->values();
            $zohoInvoices = $zohoInvoices->whereIn('project_id', $projectIds)->values();
            $zohoQuotes = $zohoQuotes->whereIn('project_id', $projectIds)->values();
            $zohoOpeningBalances = $zohoOpeningBalances->whereIn('client_id', Project::whereIn('id', $projectIds)->pluck('client_id'))->values();
        }

        $portalPaid = $portalInvoices->sum(fn ($i) => $i->payments->where('status', 'Completed')->sum('amount'));
        $portalBilled = (float) $portalInvoices->sum('total_amount');
        $portalOutstanding = (float) $portalInvoices->sum('balance_due');
        $zohoBilled = (float) $zohoInvoices->sum('total');
        $zohoCollected = (float) $zohoInvoices->sum(fn ($i) => max(0, (float) $i->total - (float) ($i->balance ?? $i->total)));
        $zohoOutstanding = (float) $zohoInvoices->sum('balance');
        $zohoOpeningBalance = (float) $zohoOpeningBalances->sum('balance');

        $portalPrefixes = $portalQuotes->where('status', 'Accepted')->count();
        $zohoInvoicePrefixes = $zohoInvoices->map(fn ($r) => substr(strtoupper((string) $r->number), 0, 9))->filter()->unique();
        $zohoConverted = $zohoQuotes->filter(fn ($q) => $zohoInvoicePrefixes->contains(substr(strtoupper((string) $q->number), 0, 9)))->count();

        return [
            'currency' => 'INR',
            'generated_at' => now()->toIso8601String(),
            'portal' => $this->source($portalInvoices, $portalQuotes, $portalBilled, $portalPaid, $portalOutstanding, $portalPrefixes, $this->monthlyPortal($portalInvoices)),
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

    private function monthlyPortal(Collection $invoices): array
    {
        $months = $this->months();
        foreach ($invoices as $invoice) {
            $key = optional($invoice->issue_date)->format('Y-m');
            if (isset($months[$key])) $months[$key]['billed'] += (float) $invoice->total_amount;
            foreach ($invoice->payments->where('status', 'Completed') as $payment) {
                $paymentKey = optional($payment->payment_date)->format('Y-m');
                if (isset($months[$paymentKey])) $months[$paymentKey]['collected'] += (float) $payment->amount;
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
