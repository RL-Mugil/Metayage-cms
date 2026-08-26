<?php

namespace App\Console\Commands;

use App\Models\ZohoInvoice;
use App\Services\ZohoBooksService;
use App\Services\ZohoCaseMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncZohoBooks extends Command
{
    protected $signature   = 'zoho:sync';
    protected $description = 'Mirror Zoho Books invoices/estimates into the local zoho_invoices table (read-only)';

    public function handle(ZohoBooksService $zoho, ZohoCaseMatcher $matcher): int
    {
        if (! $zoho->isConfigured()) {
            $this->warn('Zoho Books is not configured — skipping sync.');
            return self::SUCCESS;
        }

        $uinIndex    = $matcher->globalUinIndex();
        $docketIndex = $matcher->globalDocketIndex();
        $matched     = 0;
        $unmatched   = 0;

        try {
            foreach ($zoho->listAllInvoices() as $record) {
                $this->syncRecord($record, 'invoice', 'invoice_id', 'invoice_number', $matcher, $uinIndex, $docketIndex, $matched, $unmatched);
            }
            foreach ($zoho->listAllEstimates() as $record) {
                $this->syncRecord($record, 'quote', 'estimate_id', 'estimate_number', $matcher, $uinIndex, $docketIndex, $matched, $unmatched);
            }
        } catch (Throwable $e) {
            DB::table('integration_logs')->insert([
                'slug' => 'zoho', 'event_type' => 'sync', 'status' => 'fail',
                'summary' => 'Sync failed: ' . $e->getMessage(),
                'payload' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        DB::table('integration_logs')->insert([
            'slug' => 'zoho', 'event_type' => 'sync', 'status' => 'ok',
            'summary' => "Synced {$matched} records matched to a case ({$unmatched} unmatched, skipped).",
            'payload' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->info("Zoho Books sync done: {$matched} matched, {$unmatched} unmatched.");
        return self::SUCCESS;
    }

    private function syncRecord(
        array $record,
        string $type,
        string $idField,
        string $numberField,
        ZohoCaseMatcher $matcher,
        Collection $uinIndex,
        Collection $docketIndex,
        int &$matched,
        int &$unmatched,
    ): void {
        $zohoId = (string) ($record[$idField] ?? '');
        $number = (string) ($record[$numberField] ?? '');
        if ($zohoId === '' || $number === '') {
            return;
        }

        $match = $matcher->match($number, $type, $uinIndex, $docketIndex);
        if (! $match['project_id']) {
            $unmatched++;
            return;
        }
        $matched++;

        ZohoInvoice::updateOrCreate(
            ['zoho_type' => $type, 'zoho_id' => $zohoId],
            [
                'zoho_contact_id' => $record['customer_id'] ?? null,
                'client_id'       => $match['client_id'],
                'project_id'      => $match['project_id'],
                'number'          => $number,
                'status'          => $record['status'] ?? null,
                'total'           => $record['total'] ?? 0,
                'balance'         => $record['balance'] ?? null,
                'currency'        => $record['currency_code'] ?? 'INR',
                'txn_date'        => $record['date'] ?? null,
                'due_date'        => $record['due_date'] ?? null,
                'url'             => $record['invoice_url'] ?? null,
                'application_no'  => $record['cf_application_no'] ?? null,
                'patent_office'   => $record['cf_patent_office'] ?? null,
                'match_source'    => $match['source'],
                'synced_at'       => now(),
            ]
        );
    }
}
