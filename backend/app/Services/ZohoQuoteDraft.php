<?php

namespace App\Services;

use App\Models\PatentInvoiceIn;
use App\Models\Quotation;
use App\Models\ZohoSnapshot;
use Illuminate\Validation\ValidationException;

/** Builds a review draft solely from local records; no remote lookups or writes. */
class ZohoQuoteDraft
{
    public function source(string $kind, int $id): Quotation|PatentInvoiceIn
    {
        abort_unless(in_array($kind, ['standard', 'in'], true), 404);
        $quote = $kind === 'standard' ? Quotation::findOrFail($id) : PatentInvoiceIn::where('type', 'quote')->findOrFail($id);
        return $quote->load(['client', 'project']);
    }

    public function contacts(string $scope, string $search): array
    {
        $options = [];
        foreach (ZohoSnapshot::where('scope', $scope)->where('resource', 'contacts')->orderBy('id')->cursor() as $snapshot) {
            $contact = json_decode($snapshot->detail ?: $snapshot->summary, true) ?? [];
            $name = (string) ($contact['contact_name'] ?? $contact['company_name'] ?? $snapshot->remote_id);
            if (($contact['status'] ?? '') !== 'active' || ($contact['contact_type'] ?? '') !== 'customer') {
                continue;
            }
            if ($search !== '' && ! str_contains(mb_strtolower($name . ' ' . $snapshot->remote_id), mb_strtolower($search))) {
                continue;
            }
            $options[] = ['id' => $snapshot->remote_id, 'name' => $name];
            if (count($options) >= 50) {
                break;
            }
        }
        return $options;
    }

    public function build(Quotation|PatentInvoiceIn $quote, string $scope, string $customerId): array
    {
        $this->require(! ($quote instanceof PatentInvoiceIn) || $quote->type === 'quote', 'Only quotations can be sent to Zoho.');
        $this->require(in_array($quote->status, ['Draft', 'Sent', 'Accepted'], true), 'Only active quotations can be sent.');
        $this->require($quote->client !== null, 'Link this quotation to a portal client first.');
        $this->require(! $quote->project || (int) $quote->project->client_id === (int) $quote->client_id, 'The case and quotation must belong to the same client.');
        $linkedId = (string) $quote->client->zoho_contact_id;
        $this->require($linkedId === '' || $linkedId === $customerId, 'Select the Zoho customer already linked to this client.');
        $snapshot = ZohoSnapshot::where('scope', $scope)->where('resource', 'contacts')->where('remote_id', $customerId)->first();
        $contact = json_decode($snapshot?->detail ?? '{}', true) ?? [];
        $this->require(($contact['status'] ?? '') === 'active' && ($contact['contact_type'] ?? '') === 'customer', 'Select an active customer with details downloaded by the nightly sync.');
        $this->require(! empty($contact['currency_id']) && ($contact['currency_code'] ?? '') === $quote->currency, 'The Zoho customer currency must match this quotation.');

        $isIn = $quote instanceof PatentInvoiceIn;
        $code = $isIn ? $quote->invoice_uin : $quote->quote_code;
        $reference = 'IPFLOW-' . ($isIn ? 'INQ-' : 'Q-') . $quote->id;
        $amounts = $isIn ? [
            'Professional services' => round((float) $quote->service_fees - (float) $quote->discount_amount, 2),
            'Patent office fees' => (float) $quote->patent_office_fees,
            'Other expenses' => (float) $quote->other_expenses,
        ] : ['Professional services' => (float) $quote->subtotal];
        $lines = [];
        foreach ($amounts as $name => $amount) {
            $this->require(is_finite($amount) && $amount >= 0, 'Correct negative or invalid quotation amounts before sending.');
            if ($amount > 0) {
                $lines[] = ['name' => $name, 'description' => trim($code . ' ' . ($quote->project?->docket_number ?? '')), 'quantity' => 1, 'rate' => round($amount, 2)];
            }
        }
        $this->require($lines !== [], 'The quotation must contain a positive fee.');
        $tax = $isIn ? round((float) $quote->igst_amount + (float) $quote->cgst_amount + (float) $quote->sgst_amount, 2) : (float) $quote->tax_amount;
        $total = (float) ($isIn ? $quote->invoice_amount : $quote->total_amount);
        $this->require(is_finite($tax) && $tax >= 0 && is_finite($total) && $total > 0, 'Correct invalid tax or total amounts before sending.');
        $this->require(abs(round(array_sum($amounts) + $tax, 2) - $total) <= 0.02, 'Quotation totals are inconsistent. Correct the quotation before sending.');
        $payload = [
            'customer_id' => $customerId, 'currency_id' => (string) $contact['currency_id'],
            'reference_number' => $reference, 'date' => now('Asia/Kolkata')->toDateString(),
            'is_inclusive_tax' => false, 'line_items' => $lines,
            'notes' => "Portal quote {$code}. Accountant review required: verify taxes and totals before invoicing. Portal expected tax: {$quote->currency} {$tax}; total: {$quote->currency} {$total}. Keep reference {$reference} on the invoice.",
        ];
        if (! $isIn && $quote->valid_until) {
            $this->require(! $quote->valid_until->lt(now('Asia/Kolkata')->startOfDay()), 'Extend the quotation validity before sending.');
            $payload['expiry_date'] = $quote->valid_until->toDateString();
        }
        return ['payload' => $payload, 'version' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'customer_name' => $contact['contact_name'] ?? $contact['company_name'] ?? $customerId,
            'client_name' => $quote->client->company_name ?? $quote->client->legal_name,
            'currency' => $quote->currency, 'portal_tax' => $tax, 'portal_total' => $total];
    }

    private function require(bool $valid, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['quotation' => $message]);
        }
    }
}
