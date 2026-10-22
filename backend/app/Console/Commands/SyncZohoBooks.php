<?php

namespace App\Console\Commands;

use App\Services\ZohoBooksService;
use App\Services\ZohoMirrorSync;
use Illuminate\Console\Command;

class SyncZohoBooks extends Command
{
    protected $signature = 'zoho:sync {--scheduled : Morning scheduler run} {--evening : Resume an eligible morning run} {--emergency : Resume the saved cursor using the 200-call emergency reserve} {--addon : Resume the saved cursor using the configured temporary add-on reserve}';
    protected $description = 'Resume the daily budgeted Zoho Books mirror';

    public function handle(ZohoBooksService $zoho, ZohoMirrorSync $sync): int
    {
        if (! $zoho->isConfigured()) {
            $this->warn('Zoho Books is not configured; skipping sync.');
            return self::SUCCESS;
        }
        $result = $sync->run(null, (bool) $this->option('evening'), (bool) $this->option('scheduled'), (bool) $this->option('emergency'), (bool) $this->option('addon'));
        $this->line($result['message']);
        // "paused" (daily allowance used, not eligible for the evening run) and "busy" are normal, expected states: only a
        // real failure should make the scheduled task fail and appear in the health report.
        return in_array($result['status'], ['ok', 'paused', 'busy'], true) ? self::SUCCESS : self::FAILURE;
    }
}
