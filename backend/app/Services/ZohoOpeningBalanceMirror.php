<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ZohoInvoice;
use App\Models\ZohoOpeningBalance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ZohoOpeningBalanceMirror
{
    public const DEFAULT_OPENING_DATE = '2026-03-31';

    /** The day the books moved to Zoho: opening balances are dated on it. */
    public static function openingDate(): string
    {
        $value = DB::table('system_settings')->where('key', 'zoho_opening_balance_date')->value('value');

        return $value ? Carbon::parse($value)->toDateString() : self::DEFAULT_OPENING_DATE;
    }

    /** Mirror customer opening balances returned with a hydrated Zoho contact. */
    public function save(string $scope, array $contact): void
    {
        $contactId = (string) ($contact['contact_id'] ?? '');
        if ($contactId === '' || ($contact['contact_type'] ?? 'customer') !== 'customer') {
            return;
        }

        $rows = $contact['opening_balances'] ?? [];
        $rows = is_array($rows) ? array_values($rows) : [];
        $customerName = $contact['company_name'] ?? $contact['contact_name'] ?? $contact['legal_name'] ?? null;
        $clientId = Client::query()->where('zoho_contact_id', $contactId)->value('id');
        if (! $clientId && $customerName) {
            $clientId = Client::query()->where(function ($query) use ($customerName) {
                $query->where('company_name', $customerName)->orWhere('legal_name', $customerName);
            })->value('id');
        }
        $currentReceivable = array_key_exists('outstanding_receivable_amount', $contact)
            ? max(0, (float) $contact['outstanding_receivable_amount']) : null;
        $invoiceBalance = (float) ZohoInvoice::query()
            ->where('zoho_contact_id', $contactId)->where('zoho_type', 'invoice')->sum('balance');
        $residualBalance = $currentReceivable === null
            ? null : max(0, $currentReceivable - $invoiceBalance);
        $seen = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $location = (string) ($row['location_id'] ?? 'default');
            $dateValue = $row['date'] ?? $row['opening_balance_date'] ?? null;
            // Zoho sends no date: an opening balance is what was owed when the books moved to Zoho,
            // so it is dated on that day (system setting, 31-03-2026), never the day it was synced.
            $date = $dateValue ? Carbon::parse($dateValue)->toDateString() : self::openingDate();
            $amount = (float) ($row['opening_balance_amount'] ?? $row['amount'] ?? $row['bcy_amount'] ?? 0);
            $balance = $residualBalance === null ? $amount : min($amount, $residualBalance);
            if ($residualBalance !== null) {
                $residualBalance = max(0, $residualBalance - $balance);
            }
            $currency = strtoupper((string) ($row['currency_code'] ?? $contact['currency_code'] ?? 'INR'));
            $identity = $location . '|' . $date;
            $seen[] = $identity;
            ZohoOpeningBalance::updateOrCreate(
                ['scope' => $scope, 'zoho_contact_id' => $contactId, 'location_id' => $location, 'balance_date' => $date],
                [
                    'client_id' => $clientId,
                    'customer_name' => $customerName,
                    'amount' => $amount,
                    'balance' => $balance,
                    'currency' => $currency,
                    'source' => 'Zoho Opening Balance',
                    'synced_at' => now(),
                ],
            );
        }

        $query = ZohoOpeningBalance::withoutGlobalScopes()
            ->where('scope', $scope)->where('zoho_contact_id', $contactId);
        if ($seen === []) {
            $query->delete();
        } else {
            $query->whereNotIn(DB::raw("location_id || '|' || balance_date::text"), $seen)->delete();
        }
    }
}
