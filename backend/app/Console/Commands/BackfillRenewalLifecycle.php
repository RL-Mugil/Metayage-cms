<?php

namespace App\Console\Commands;

use App\Http\Controllers\RenewalActionController;
use App\Models\PatentApplication;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\RenewalSchedule;
use App\Models\User;
use App\Services\InventionFamilyService;
use App\Support\DocketRules;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One-time catch-up for the grant/renewal lifecycle automation, run manually after deploy
 * — not automatically on migrate. Covers records that predate this feature:
 *   1. REN/RNF projects not yet marked patent_granted (createProjectWithCodes()/update()
 *      only auto-grant going forward).
 *   2. REN/RNF projects still seeded with the old stage list — re-seeds to the new
 *      19-stage list (DocketRules::renewalStageNames()), remapping existing progress by
 *      completion ratio rather than wiping it.
 *   3. Granted applications with no REN/RNF successor engagement yet — auto-creates one
 *      (DocketController::storeRenewalPaid()'s auto-create logic, applied retroactively).
 *   4. Granted applications with no RenewalSchedule at all — generates the baseline
 *      schedule (DocketRules::generateRenewalSchedule(), which never touches Paid/Waived
 *      rows, so this is always safe to (re-)run).
 *   5. RenewalSchedule rows already within 90 days of due (or overdue) with no linked
 *      quote — auto-creates one now rather than waiting for the next natural 90-day
 *      threshold crossing, which an already-overdue row would never hit again.
 *
 * --dry-run reports what would happen without writing anything.
 */
class BackfillRenewalLifecycle extends Command
{
    protected $signature = 'patents:backfill-renewal-lifecycle {--dry-run}';
    protected $description = 'One-time catch-up applying the grant/renewal lifecycle automation to existing records';

    public function handle(RenewalActionController $renewalActions, InventionFamilyService $familyService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? 'Would ' : '';

        $counts = [
            'projects_granted' => 0,
            'stages_reseeded' => 0,
            'successors_created' => 0,
            'schedules_generated' => 0,
            'quotes_created' => 0,
        ];

        // ── 1. Auto-grant REN/RNF projects that predate this feature ──
        $renProjects = Project::withTrashed()
            ->whereIn('service_code', ['REN', 'RNF'])
            ->where('patent_granted', false)
            ->get();
        $this->info("Checking {$renProjects->count()} REN/RNF project(s) not yet marked granted.");
        foreach ($renProjects as $project) {
            $this->line("{$prefix}grant #{$project->id} ({$project->docket_number})");
            if (! $dryRun) {
                $project->update(['patent_granted' => true]);
                if ($project->patent_application_id) {
                    app(\App\Http\Controllers\ProjectController::class)->syncApplicationLegalStatus(
                        $project, 'Granted', null, Carbon::parse($project->created_at)
                    );
                }
            }
            $counts['projects_granted']++;
        }

        // ── 2. Re-seed REN/RNF stage lists that don't match the new 19-stage list ──
        // Deliberately NOT a completion-ratio remap: the old generic stage list was never
        // actually used to track real renewal payments (it silently fell through to a
        // default for every REN-coded project), so "N/8 completed" on it carries no signal
        // about which renewal years are genuinely paid. Remapping by ratio would mark many
        // projects "Renewed till 20th year" on the Pipeline tab while the real source of
        // truth — RenewalSchedule — still shows those years Unpaid underneath, a misleading
        // display divergence. Instead every REN/RNF project resets to "Not renewed" (stage
        // 0, In Progress) and is driven forward from here only by real docketed payments
        // (DocketController::storeRenewalPaid()), which also updates RenewalSchedule in
        // lockstep — see verification step 12.
        $newStageNames = DocketRules::renewalStageNames();
        $renAll = Project::withTrashed()->whereIn('service_code', ['REN', 'RNF'])->with('stages')->get();
        foreach ($renAll as $project) {
            $currentNames = $project->stages->pluck('stage_name')->all();
            if ($currentNames === $newStageNames) {
                continue; // already on the new list
            }
            $total = count($currentNames);
            $completed = $project->stages->where('status', 'Completed')->count();
            $this->line("{$prefix}re-seed stages for #{$project->id} ({$project->docket_number}) — {$completed}/{$total} completed on old list, resetting to \"Not renewed\"");
            if (! $dryRun) {
                $project->stages()->delete();
                foreach ($newStageNames as $i => $name) {
                    ProjectStage::create([
                        'project_id' => $project->id,
                        'stage_name' => $name,
                        'sequence_order' => $i,
                        'status' => $i === 0 ? 'In Progress' : 'Pending',
                        'duration_days' => 0,
                        'gate_criteria' => json_encode([]),
                    ]);
                }
            }
            $counts['stages_reseeded']++;
        }

        // ── 3. Granted applications with no REN/RNF successor yet ──
        $actor = User::where('role', 'super_admin')->orderBy('id')->first();
        $grantedApps = PatentApplication::where('legal_status', 'Granted')->get();
        $this->info("Checking {$grantedApps->count()} granted application(s) for a REN/RNF successor.");
        foreach ($grantedApps as $app) {
            $hasSuccessor = Project::where('patent_application_id', $app->id)->whereIn('service_code', ['REN', 'RNF'])->exists();
            if ($hasSuccessor) {
                continue;
            }
            $source = Project::withTrashed()->where('patent_application_id', $app->id)->orderByDesc('id')->first();
            if (! $source) {
                continue; // no engagement to branch from — shouldn't happen, skip defensively
            }
            if (! $actor) {
                $this->warn("Skipping successor creation for application #{$app->id} — no super_admin user found to attribute it to.");
                continue;
            }
            $this->line("{$prefix}create RNF successor for application #{$app->id} from {$source->docket_number}");
            if (! $dryRun) {
                try {
                    $familyService->createBranch($source, [
                        'patent_office_code' => $source->patent_office_code,
                        'service_code' => 'RNF',
                        'note' => 'Backfill: auto-created REN/RNF successor for already-granted application',
                        'complete_source' => true,
                    ], $actor);
                    $counts['successors_created']++;
                } catch (\Throwable $e) {
                    $this->warn("Could not create successor for application #{$app->id}: {$e->getMessage()}");
                }
            } else {
                $counts['successors_created']++;
            }
        }

        // ── 4. Granted applications with no RenewalSchedule at all ──
        $needSchedule = PatentApplication::where('legal_status', 'Granted')
            ->whereDoesntHave('renewals')
            ->whereNotNull('filing_date') // generateRenewalSchedule() no-ops without one anyway
            ->get();
        $this->info("Checking {$needSchedule->count()} granted application(s) with no renewal schedule.");
        foreach ($needSchedule as $app) {
            $this->line("{$prefix}generate renewal schedule for application #{$app->id} ({$app->application_number})");
            if (! $dryRun) {
                DocketRules::generateRenewalSchedule($app, $app->grant_date ? Carbon::parse($app->grant_date) : Carbon::now());
            }
            $counts['schedules_generated']++;
        }

        // ── 5. Renewal years already within 90 days (or overdue) with no linked quote ──
        $today = Carbon::today();
        $needQuote = RenewalSchedule::whereNull('patent_invoice_in_id')
            ->where('status', 'Unpaid')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $today->copy()->addDays(90)->toDateString())
            ->with('application.client', 'application.projects')
            ->get();
        $this->info("Checking {$needQuote->count()} renewal year(s) within 90 days (or overdue) with no quote yet.");
        foreach ($needQuote as $renewal) {
            $this->line("{$prefix}auto-create renewal quote for schedule #{$renewal->id} (year {$renewal->renewal_year})");
            if (! $dryRun) {
                $quote = $renewalActions->autoCreateQuote($renewal);
                if ($quote) {
                    $counts['quotes_created']++;
                }
            } else {
                $counts['quotes_created']++;
            }
        }

        $this->newLine();
        $this->info(($dryRun ? 'Dry run complete. Would apply: ' : 'Done. Applied: ') . collect($counts)
            ->map(fn ($n, $key) => "{$n} {$key}")
            ->implode(', '));

        return self::SUCCESS;
    }
}
