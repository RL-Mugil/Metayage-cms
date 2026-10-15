<?php

namespace App\Console\Commands;

use App\Services\ZohoMirrorSync;
use Illuminate\Console\Command;

class ReconcileZohoDeletions extends Command
{
    protected $signature = 'zoho:reconcile-deleted';
    protected $description = 'Remove portal invoices and quotes that were deleted in Zoho Books (reads the Zoho lists; about 7 API calls)';

    public function handle(ZohoMirrorSync $sync): int
    {
        $result = $sync->reconcileDeleted();
        $this->line($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
