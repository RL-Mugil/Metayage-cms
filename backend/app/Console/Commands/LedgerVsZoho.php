<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientLedger;
use App\Models\ZohoSnapshot;
use Illuminate\Console\Command;

/**
 * Read-only check: each client's ledger closing balance against Zoho Books' own figure for the
 * linked customer (receivable minus unused credits, INR). Prints only the clients that differ.
 */
class LedgerVsZoho extends Command
{
    protected $signature = 'finance:ledger-vs-zoho {--tolerance=1 : Differences up to this many rupees are ignored}';

    protected $description = 'Compare each client ledger balance with Zoho Books (read-only)';

    public function handle(): int
    {
        $tolerance = (float) $this->option('tolerance');
        $contacts = ZohoSnapshot::where('resource', 'contacts')->get(['remote_id', 'detail', 'summary'])
            ->mapWithKeys(fn ($s) => [(string) $s->remote_id => json_decode((string) ($s->detail ?: $s->summary), true) ?: []]);

        $rows = [];
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
            $last = ClientLedger::where('client_id', $client->id)
                ->orderByDesc('transaction_date')->orderByRaw('CASE WHEN debit > 0 THEN 1 ELSE 0 END')->orderByDesc('id')->value('balance');
            $portal = round((float) ($last ?? 0), 2);
            if (abs($portal - $zoho) > $tolerance) {
                $rows[] = [$client->client_code, mb_strimwidth((string) ($client->legal_name ?: $client->company_name), 0, 40, '…'),
                    number_format($portal, 2), number_format($zoho, 2), number_format($portal - $zoho, 2)];
            }
        }

        $this->info("Checked {$checked} linked clients; ".count($rows).' differ (minus = credit with us).');
        if ($rows) {
            $this->table(['Code', 'Client', 'Portal ledger', 'Zoho', 'Difference'], $rows);
        }

        return self::SUCCESS;
    }
}
