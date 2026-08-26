<?php

namespace App\Console\Commands;

use App\Models\Firm;
use App\Services\ComplianceSyncService;
use App\Support\FirmContext;
use Illuminate\Console\Command;

class SyncComplianceItems extends Command
{
    protected $signature = 'compliance:sync {--firm= : Firm ID to synchronize}';
    protected $description = 'Synchronize compliance items from live project, docket, and renewal deadlines';

    public function handle(ComplianceSyncService $service, FirmContext $context): int
    {
        $firms = Firm::active()
            ->when($this->option('firm'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        if ($firms->isEmpty()) {
            $this->error('No active firm matched the requested scope.');
            return self::FAILURE;
        }

        foreach ($firms as $firm) {
            $counts = $context->run($firm, fn () => $service->sync());
            $this->info("{$firm->name}: {$counts['created']} created, {$counts['updated']} updated, {$counts['resolved']} resolved; {$counts['total']} active.");
        }

        return self::SUCCESS;
    }
}
