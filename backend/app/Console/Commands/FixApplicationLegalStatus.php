<?php

namespace App\Console\Commands;

use App\Models\PatentApplication;
use App\Models\Project;
use App\Services\ApplicationNumberSyncService;
use Illuminate\Console\Command;

/**
 * One-time correction for PatentApplication rows that were created with a
 * hardcoded 'Pending' legal_status regardless of the linked project's real
 * outcome (fixed at the source in ApplicationNumberSyncService::deriveLegalStatus() —
 * this command catches up applications created before that fix, e.g. via the
 * patents:backfill-applications run). Without this, the IPO-style status
 * pipeline shows "Filed" only for cases that are actually Granted/Refused/Abandoned.
 */
class FixApplicationLegalStatus extends Command
{
    protected $signature = 'patents:fix-legal-status {--dry-run}';
    protected $description = "Correct PatentApplication.legal_status='Pending' rows whose linked project already shows a real outcome";

    public function handle(ApplicationNumberSyncService $sync): int
    {
        $apps = PatentApplication::where('legal_status', 'Pending')->get();
        $this->info("Checking {$apps->count()} application(s) currently marked Pending.");

        $fixed = 0;
        foreach ($apps as $app) {
            $project = Project::where('patent_application_id', $app->id)
                ->where(fn ($q) => $q->where('patent_granted', true)->orWhereIn('status', ['Granted', 'Refused', 'Abandoned']))
                ->first();
            if (! $project) {
                continue;
            }

            $newStatus = $sync->deriveLegalStatus($project);
            if ($newStatus === 'Pending') {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("Would set application #{$app->id} ({$app->application_number}) to {$newStatus} — from project {$project->docket_number}");
            } else {
                $app->update(['legal_status' => $newStatus]);
            }
            $fixed++;
        }

        $this->info($this->option('dry-run') ? "Dry run complete — {$fixed} would be fixed." : "Fixed {$fixed} application(s).");

        return self::SUCCESS;
    }
}
