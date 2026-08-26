<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ApplicationNumberSyncService;
use Illuminate\Console\Command;

/**
 * One-time backfill for legacy Projects that have application_number set
 * directly on the row but were never linked to a PatentApplication (they
 * predate ApplicationNumberSyncService, added this session) — the IPO-style
 * status panel on the case page only renders once patentApplication exists.
 *
 * Reuses ApplicationNumberSyncService::backfill() exactly as a live edit
 * would — same docket-prefix (client+invention+office) matching, so this
 * never merges across jurisdictions, only catches up existing data to what
 * an edit would have produced from here on.
 */
class BackfillPatentApplications extends Command
{
    protected $signature = 'patents:backfill-applications {--dry-run}';
    protected $description = 'Link legacy projects (application_number set, no patent_application_id) to a PatentApplication';

    public function handle(ApplicationNumberSyncService $sync): int
    {
        $projects = Project::whereNotNull('application_number')
            ->where('application_number', '!=', '')
            ->whereNull('patent_application_id')
            ->get();

        $this->info("Found {$projects->count()} legacy project(s) with an application_number but no linked PatentApplication.");

        $linked = 0;
        foreach ($projects as $project) {
            // Re-fetch — an earlier sibling in this same loop may have already
            // linked this project via the docket-prefix backfill.
            $fresh = $project->fresh();
            if (! $fresh || $fresh->patent_application_id) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("Would backfill {$fresh->docket_number} ({$fresh->application_number})");
                continue;
            }

            $sync->backfill($fresh, $fresh->application_number);
            $linked++;
        }

        $this->info($this->option('dry-run') ? 'Dry run complete — no changes made.' : "Linked {$linked} project(s) to a PatentApplication.");

        return self::SUCCESS;
    }
}
