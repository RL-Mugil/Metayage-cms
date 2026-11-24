<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\DocketNumberService;
use App\Services\ServiceChangeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cases whose workflow (lifecycle template) is for a different service than their service
 * code — e.g. imported with the current service but an older docket and stages, or edited
 * before a service change rebuilt the workflow. The service code is the truth: the docket
 * is renamed to match (skipped when that docket already exists) and the workflow rebuilt
 * with ServiceChangeService (intake progress carried, old stages snapshotted).
 */
class SyncServiceWorkflow extends Command
{
    protected $signature = 'projects:sync-service-workflow {--dry-run : List what would change without changing anything} {--docket= : Only this docket}';

    protected $description = 'Rebuild the workflow (and docket) of cases whose stages are for a different service than their service code';

    public function handle(ServiceChangeService $changes, DocketNumberService $dockets): int
    {
        $query = Project::query()
            ->join('jurisdiction_lifecycle_templates as t', 't.id', '=', 'projects.jurisdiction_lifecycle_template_id')
            ->whereRaw('UPPER(t.service_code) <> UPPER(projects.service_code)')
            ->where('t.service_code', '!=', '*')
            ->select('projects.*', 't.service_code as template_service')
            ->orderBy('projects.id');
        if ($docket = $this->option('docket')) {
            $query->where('projects.docket_number', strtoupper($docket));
        }
        $dry = (bool) $this->option('dry-run');
        $rows = [];
        $skipped = [];

        foreach ($query->get() as $row) {
            $project = Project::findOrFail($row->id);
            $from = strtoupper((string) $row->template_service);
            $oldDocket = $project->docket_number;
            $current = $project->stages()->where('status', 'In Progress')->value('stage_name') ?? '—';
            try {
                $codes = $dockets->recanonicalize($project, $project->patent_office_code, $project->service_code);
            } catch (ValidationException $e) {
                $skipped[] = [$oldDocket, $project->service_code, collect($e->errors())->flatten()->first()];
                continue;
            }

            if ($dry) {
                $preview = $changes->preview($project, (string) $project->patent_office_code, (string) $project->service_code);
                $rows[] = [$oldDocket, $codes['docket_number'], "{$from} → {$project->service_code}", $project->status, $current, $preview['lands_on'] ?? 'all complete', $preview['workflow']];

                continue;
            }

            $summary = DB::transaction(function () use ($project, $codes, $from, $oldDocket, $changes) {
                if ($codes['docket_number'] !== $oldDocket) {
                    $project->update(['docket_number' => $codes['docket_number'], 'project_code' => $codes['project_code']]);
                }

                return $changes->apply($project->fresh(), $from, $oldDocket, null, 'service-workflow-sync');
            });
            $rows[] = [$oldDocket, $summary['to_docket'], "{$from} → {$summary['to_service']}", $project->status, $current, $summary['lands_on'] ?? 'all complete', (string) $summary['template']];
        }

        $this->table(['Docket', 'New docket', 'Service', 'Status', 'Was at', 'Now at', 'Workflow'], $rows);
        if ($skipped) {
            $this->warn(count($skipped).' skipped (docket clash or unreadable docket):');
            $this->table(['Docket', 'Service', 'Reason'], $skipped);
        }
        $this->info(($dry ? 'Would change ' : 'Changed ').count($rows).' case(s).');

        return self::SUCCESS;
    }
}
