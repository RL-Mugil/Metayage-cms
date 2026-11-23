<?php

namespace App\Console\Commands;

use App\Services\ZohoRegisterImporter;
use Illuminate\Console\Command;

/**
 * One-time (and repeatable) import of every mirrored Zoho Books invoice and quote into the
 * Indian Patents (UIN) register. --dry-run reports what would happen, including line-item
 * names the fee split could not classify, without writing anything.
 */
class ImportZohoToRegister extends Command
{
    protected $signature = 'finance:import-zoho {--dry-run : Report only, write nothing}';

    protected $description = 'Import Zoho Books invoices and quotes into the portal register (the single source of truth)';

    public function handle(ZohoRegisterImporter $importer): int
    {
        $dry = (bool) $this->option('dry-run');
        $report = $importer->importAll($dry);

        $this->info($dry ? 'DRY RUN — nothing written.' : 'Import finished.');
        $this->table(['', 'Records'], [
            ['New register rows', $report['created']],
            ['Already imported (refreshed)', $report['updated']],
            ['Linked to an existing portal row', $report['linked']],
            ['Deleted in Zoho → Cancelled', $report['cancelled']],
            ['Deleted in Zoho before import (not imported)', $report['skipped']],
            ['Without a matched client', $report['no_client']],
            ['Without a matched case', $report['no_case']],
            ['Amount split pending (no line items yet)', $report['split_pending']],
            [$dry ? 'Zoho customer payments to import' : 'Zoho customer payments imported', $report['payments']],
            ['Client ledgers rebuilt', $dry ? '-' : $report['ledgers']],
        ]);
        $this->line(sprintf('Invoices total: ₹%s   Quotes total: ₹%s',
            number_format($report['totals']['invoice'], 2), number_format($report['totals']['quote'], 2)));

        if ($report['unclassified_items']) {
            $this->line('Line items counted as service fees (check none of these is a Patent Office fee or an expense):');
            $this->table(['Item', 'Times'], collect($report['unclassified_items'])->map(fn ($count, $name) => [$name, $count])->values()->all());
        }

        return self::SUCCESS;
    }
}
