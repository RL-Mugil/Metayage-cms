<?php

namespace App\Console\Commands;

use App\Services\InsightPublisher;
use Illuminate\Console\Command;

class RepublishCpiContent extends Command
{
    protected $signature = 'cpi:republish';

    protected $description = 'Rewrite the FAQ page and every published article on canpatentit.com (run after a site deploy so they pick up the new header and footer)';

    public function handle(InsightPublisher $publisher): int
    {
        $count = $publisher->republishAll();
        $this->info("Republished the FAQ page and {$count} article(s).");

        return self::SUCCESS;
    }
}
