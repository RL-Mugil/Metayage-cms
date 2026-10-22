<?php

namespace App\Console\Commands;

use App\Services\OpsDailyReport;
use Illuminate\Console\Command;

class OpsDailyReportCommand extends Command
{
    protected $signature = 'ops:daily-report {--dry-run : print the report instead of emailing it} {--force : send even if one already went out today}';
    protected $description = 'Email the single daily health report (healthy or not) to the ops address';

    public function handle(OpsDailyReport $report): int
    {
        if ($this->option('dry-run')) {
            $r = $report->build();
            $this->line($r['subject']);
            $this->line('');
            $this->line($r['text']);

            return self::SUCCESS;
        }

        $this->line($report->sendIfDue((bool) $this->option('force')) ? 'Daily report sent.' : 'Nothing sent (already sent today, or no recipient configured).');

        return self::SUCCESS;
    }
}
