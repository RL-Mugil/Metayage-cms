<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ZohoOpeningBalance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ZohoOpeningBalanceMirror
{
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
        $seen = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $location = (string) ($row['location_id'] ?? 'default');
            $dateValue = $row['date'] ?? $row['opening_balance_date'] ?? null;
            $date = $dateValue ? Carbon::parse($dateValue)->toDateString() : now()->toDateString();
            $amount = (float) ($row['opening_balance_amount'] ?? $row['amount'] ?? $row['bcy_amount'] ?? 0);
            $currency = strtoupper((string) ($row['currency_code'] ?? $contact['currency_code'] ?? 'INR'));
            $identity = $location . '|' . $date;
            $seen[] = $identity;
            ZohoOpeningBalance::updateOrCreate(
                ['scope' => $scope, 'zoho_contact_id' => $contactId, 'location_id' => $location, 'balance_date' => $date],
                [
                    'client_id' => $clientId,
                    'customer_name' => $customerName,
                    'amount' => $amount,
                    'balance' => $amount,
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
