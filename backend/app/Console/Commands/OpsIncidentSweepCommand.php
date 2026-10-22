<?php

namespace App\Console\Commands;

use App\Services\OpsIncidentService;
use Illuminate\Console\Command;

class OpsIncidentSweepCommand extends Command
{
    protected $signature = 'ops:incidents-sweep';
    protected $description = 'Mark quiet problems as recovered and email a recovery notice for any that had been reported';

    public function handle(OpsIncidentService $incidents): int
    {
        $this->line('Recovered: '.$incidents->sweep());

        return self::SUCCESS;
    }
}
