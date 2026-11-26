<?php

namespace App\Jobs;

use App\Services\ClientLedgerSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Rebuild one client's ledger. Unique per client while waiting, so a burst of Zoho changes for the
 * same client (an import, a sync) becomes one rebuild a few seconds later instead of one per change.
 */
class RebuildClientLedger implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 120;

    public int $tries = 3;

    public function __construct(public int $clientId) {}

    public function uniqueId(): string
    {
        return 'ledger-'.$this->clientId;
    }

    public function handle(ClientLedgerSync $ledger): void
    {
        $ledger->sync($this->clientId);
    }
}
