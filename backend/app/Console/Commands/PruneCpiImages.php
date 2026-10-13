<?php

namespace App\Console\Commands;

use App\Services\InsightPublisher;
use Illuminate\Console\Command;

class PruneCpiImages extends Command
{
    protected $signature = 'cpi:prune-images {--grace=60 : Keep unreferenced files newer than this many minutes}';

    protected $description = 'Delete article images on canpatentit.com that no article uses any more';

    public function handle(InsightPublisher $publisher): int
    {
        $removed = $publisher->pruneImages((int) $this->option('grace'));
        $this->info("Removed {$removed} unused image(s).");

        return self::SUCCESS;
    }
}
