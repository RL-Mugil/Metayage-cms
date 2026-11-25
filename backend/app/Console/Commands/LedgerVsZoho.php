<?php

namespace App\Console\Commands;

use App\Services\LedgerCheck;
use Illuminate\Console\Command;

/**
 * Each client's ledger balance against Zoho Books' own figure for the linked customer. Prints only
 * the clients that differ; with --alert (nightly) the result is kept and Accounts is notified.
 */
class LedgerVsZoho extends Command
{
    protected $signature = 'finance:ledger-vs-zoho {--tolerance=1 : Differences up to this many rupees are ignored} {--alert : Keep the result and notify Accounts when any client differs}';

    protected $description = 'Compare each client ledger balance with Zoho Books';

    public function handle(LedgerCheck $check): int
    {
        $alert = (bool) $this->option('alert');
        $result = $check->run((float) $this->option('tolerance'), $alert, $alert);
        $this->info("Checked {$result['checked']} linked clients; ".count($result['differ']).' differ (minus = credit with us).');
        if ($result['differ']) {
            $this->table(['Code', 'Client', 'Portal ledger', 'Zoho', 'Difference'], array_map(fn ($r) => [
                $r['code'], mb_strimwidth((string) $r['name'], 0, 40, '…'), number_format($r['portal'], 2), number_format($r['zoho'], 2), number_format($r['difference'], 2),
            ], $result['differ']));
        }

        return self::SUCCESS;
    }
}
