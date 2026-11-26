<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientLedger;
use App\Models\User;
use App\Models\ZohoSnapshot;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Each linked client's ledger closing balance against Zoho Books' own figure for the customer
 * (receivable minus unused credits, INR). The latest result is kept for the Close & controls
 * card; with $alert, any difference notifies Accounts and raises an ops alert.
 */
class LedgerCheck
{
    public const SETTING = 'ledger_check_last';

    /** @return array{checked: int, differ: array<int, array<string, mixed>>, at: string} */
    public function run(float $tolerance = 1.0, bool $store = true, bool $alert = false): array
    {
        $contacts = ZohoSnapshot::where('resource', 'contacts')->get(['remote_id', 'detail', 'summary'])
            ->mapWithKeys(fn ($s) => [(string) $s->remote_id => json_decode((string) ($s->detail ?: $s->summary), true) ?: []]);

        $differ = [];
        $checked = 0;
        foreach (Client::whereNotNull('zoho_contact_id')->orderBy('client_code')->get(['id', 'client_code', 'legal_name', 'company_name', 'zoho_contact_id']) as $client) {
            $contact = $contacts[(string) $client->zoho_contact_id] ?? null;
            if (! $contact || ! array_key_exists('outstanding_receivable_amount', $contact)) {
                continue;
            }
            $checked++;
            // INR figures (_bcy) where Zoho gives them: a foreign customer's plain figures are in its currency.
            $zoho = round((float) ($contact['outstanding_receivable_amount_bcy'] ?? $contact['outstanding_receivable_amount'])
                - (float) ($contact['unused_credits_receivable_amount_bcy'] ?? $contact['unused_credits_receivable_amount'] ?? 0), 2);
            // Zoho's customer balance has no quotes (estimates): leave the portal's Quote lines out.
            $portal = round((float) (ClientLedger::where('client_id', $client->id)
                ->orderByDesc('transaction_date')->orderByRaw('CASE WHEN debit > 0 THEN 1 ELSE 0 END')->orderByDesc('id')->value('balance') ?? 0)
                - (float) ClientLedger::where('client_id', $client->id)->where('document_type', 'Quote')->sum('debit'), 2);
            if (abs($portal - $zoho) > $tolerance) {
                $differ[] = ['client_id' => $client->id, 'code' => $client->client_code, 'name' => $client->legal_name ?: $client->company_name,
                    'portal' => $portal, 'zoho' => $zoho, 'difference' => round($portal - $zoho, 2)];
            }
        }
        $result = ['checked' => $checked, 'differ' => $differ, 'at' => now()->toIso8601String()];

        if ($store) {
            DB::table('system_settings')->updateOrInsert(['key' => self::SETTING], ['value' => json_encode($result), 'updated_at' => now()]);
        }
        if ($alert && $differ !== []) {
            $codes = implode(', ', array_slice(array_column($differ, 'code'), 0, 10)).(count($differ) > 10 ? '…' : '');
            $accounts = User::where('status', 'Active')->get()->filter(fn ($u) => InvoiceSettlement::canPay($u))->pluck('id')->all();
            Notifier::push($accounts, 'ledger_check', 'Client ledgers differ from Zoho',
                count($differ)." of {$checked} clients differ from Zoho Books: {$codes}.", '/financial?tab=controls');
            app(OpsAlertService::class)->alert('Client ledgers differ from Zoho', count($differ)." of {$checked} clients: {$codes}", 'ledger-vs-zoho', 1380);
        }

        return $result;
    }

    public static function last(): ?array
    {
        return json_decode((string) DB::table('system_settings')->where('key', self::SETTING)->value('value'), true) ?: null;
    }
}
