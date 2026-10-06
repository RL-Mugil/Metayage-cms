<?php

namespace App\Console\Commands;

use App\Models\ZohoInvoice;
use App\Services\ZohoBooksService;
use App\Services\ZohoCaseMatcher;
use Illuminate\Console\Command;

class LinkZohoByUin extends Command
{
    protected $signature = 'zoho:link-by-uin {--dry-run : Report matches without changing records}';
    protected $description = 'Link existing local Zoho invoice and quote mirrors by their UIN';

    public function handle(ZohoBooksService $zoho, ZohoCaseMatcher $matcher): int
    {
        $uin = $matcher->globalUinIndex();
        $docket = $matcher->globalDocketIndex();
        $prefix = $matcher->globalDocketPrefixIndex();
        $linked = $unmatched = $skipped = 0;
        foreach (ZohoInvoice::query()->orderBy('id')->cursor() as $record) {
            if ($record->match_source === 'manual') {
                $skipped++;
                continue;
            }
            $match = $matcher->match((string) $record->number, (string) $record->zoho_type, $uin, $docket, $prefix);
            if ($match['project_id']) {
                $linked++;
                if (! $this->option('dry-run')) {
                    $record->update(['project_id' => $match['project_id'], 'client_id' => $match['client_id'], 'match_source' => $match['source']]);
                }
            } else {
                $unmatched++;
            }
        }
        $this->info(sprintf('%s: linked=%d unmatched=%d manual_skipped=%d', $this->option('dry-run') ? 'Dry run' : 'Completed', $linked, $unmatched, $skipped));
        return self::SUCCESS;
    }
}
