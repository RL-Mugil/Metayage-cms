<?php

namespace App\Console\Commands;

use App\Services\ZohoMirrorSync;
use Illuminate\Console\Command;

class ReconcileZohoPayments extends Command
{
    protected $signature = 'zoho:reconcile-payments {--details=80 : At most this many detail reads per run}';

    protected $description = 'Check customer payments and credit notes against Zoho Books: download missing ones, refresh changed or stale ones, remove deleted ones';

    public function handle(ZohoMirrorSync $sync): int
    {
        $result = $sync->reconcilePayments(max(1, (int) $this->option('details')));
        $this->line($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
