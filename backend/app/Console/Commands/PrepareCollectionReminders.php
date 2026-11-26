<?php

namespace App\Console\Commands;

use App\Services\CollectionsPlanner;
use Illuminate\Console\Command;

class PrepareCollectionReminders extends Command
{
    protected $signature = 'collections:prepare';

    protected $description = 'Draft payment reminders by ageing band for the Client Managers / Accounts to review and send';

    public function handle(CollectionsPlanner $planner): int
    {
        $this->info('Reminder drafts created: '.$planner->prepare()->count());

        return self::SUCCESS;
    }
}
