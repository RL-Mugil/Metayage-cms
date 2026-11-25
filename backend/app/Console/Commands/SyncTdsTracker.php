<?php

namespace App\Console\Commands;

use App\Services\TdsTracker;
use Illuminate\Console\Command;

class SyncTdsTracker extends Command
{
    protected $signature = 'finance:tds-sync';

    protected $description = 'Build the TDS tracker rows from the TDS register lines and match uploaded 26AS credits';

    public function handle(TdsTracker $tracker): int
    {
        $this->info('TDS lines: '.$tracker->syncAll().'; newly matched to 26AS: '.$tracker->match());

        return self::SUCCESS;
    }
}
