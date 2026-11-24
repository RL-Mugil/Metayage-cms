<?php

namespace App\Services;

use App\Http\Controllers\ProjectController;
use App\Models\AuditLog;
use App\Models\PatentApplication;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\TrackerRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place a case's service changes (PAS → PRV, CPT → FER …). The workflow follows
 * the service code: stages are rebuilt exactly the way a new case of that service is
 * seeded (jurisdiction template, trademark list, or the fallback list), then progress is
 * carried over with the intake rule:
 *
 *   - a stage with the same name keeps its status and dates;
 *   - the new workflow's opening intake stages (matter created / instruction / disclosure /
 *     intake) are done when the old case had reached its disclosure stage;
 *   - the first stage after that is In Progress, the rest Pending;
 *   - a Granted / Completed / Closed case keeps every stage complete.
 *
 * The old stages are kept in project_stage_migration_snapshots, and filing services get
 * their patent application link like a new case would.
 */
class ServiceChangeService
{
    private const INTAKE_NEW = '/matter created|instruction|disclosure|intake/i';

    private const INTAKE_OLD = '/disclosure|instruction|intake/i';

    private const DONE_STATUSES = ['Granted', 'Completed', 'Closed'];

    /** Filing / prosecution services: the case represents a patent application. */
    public const PROSECUTION_SERVICES = [
        'PRV', 'CPT', 'CPD', 'CVP', 'CPE', 'PCT', 'NAP', 'NPE', 'NAF', 'NPA',
        'DVA', 'PAD', '9EP', '98A', '18F', '18A', 'FER', 'SER', 'TER',
        'HRG', 'GRT', 'RNF', 'REN', 'OPP', 'PGO', '27F', 'ROA', 'ERH', '24F',
        'RPO', 'ABN', 'WDR',
        'NPV', 'NPD', 'NPP', 'NPS', 'CNS', 'DIV', 'CIP', 'OAR', 'AFT', 'RCE',
        'APP', 'ISF', 'M35', 'M75', 'M15', 'REI', 'XPR', 'REV', 'IPR', 'PGR',
    ];

    /** The firm's own US codes (Settings → Service Codes, tagged US) on a US case. */
    public const US_FIRM_SERVICES = ['PRD', 'CPT', 'PIA', 'NPE', 'CON', 'FMP', 'IDS', 'TRC', 'RRT', 'NFO', 'FOA', 'QOA', 'EXI', 'APL', 'APR', 'NOA', 'MFE', 'REN', 'PGV', 'PAM', 'C46'];

    public static function isProsecution(string $service, ?string $office): bool
    {
        $codes = strtoupper((string) $office) === 'US' ? [...self::PROSECUTION_SERVICES, ...self::US_FIRM_SERVICES] : self::PROSECUTION_SERVICES;

        return in_array(strtoupper($service), $codes, true);
    }

    /**
     * Seeds a case's stages for its current office + service — the same way for a new
     * case (ProjectController creation) and a service change.
     */
    public function seed(Project $project): void
    {
        $svc = strtoupper((string) $project->service_code);
        if (TrademarkMatterService::isTrademarkCode($svc)) {
            app(TrademarkMatterService::class)->seedStages($project);

            return;
        }
        try {
            $template = app(JurisdictionLifecycleService::class)->resolve($project->patent_office_code ?: 'IN', $svc);
            app(JurisdictionLifecycleService::class)->apply($project, $template);
        } catch (ValidationException) {
            $project->update(['jurisdiction_lifecycle_template_id' => null, 'lifecycle_template_version' => null]);
            foreach (app(ProjectController::class)->stagesForServiceCode($svc) as $index => $stageName) {
                ProjectStage::create([
                    'project_id' => $project->id,
                    'stage_name' => $stageName,
                    'status' => $index === 0 ? 'In Progress' : 'Pending',
                    'sequence_order' => $index,
                    'duration_days' => 15,
                    'due_date' => Carbon::now()->addDays(($index + 1) * 15),
                ]);
            }
        }
    }

    /**
     * Links a filing-service case to its patent application (parent's, a sibling
     * engagement's with the same docket prefix, or a new one) — as at creation.
     */
    public function linkPatentApplication(Project $project, ?\App\Models\InventionFamily $family = null): void
    {
        if ($project->patent_application_id || ! self::isProsecution((string) $project->service_code, $project->patent_office_code)) {
            return;
        }
        $family ??= app(InventionFamilyService::class)->attach($project);
        $prefix = app(ApplicationNumberSyncService::class)->docketPrefix($project->docket_number, $project->service_code);
        $parentAppId = $project->parent_project_id ? Project::where('id', $project->parent_project_id)->value('patent_application_id') : null;
        $siblingAppId = (! $parentAppId && $prefix)
            ? Project::where('client_id', $project->client_id)->where('id', '!=', $project->id)
                ->where('docket_number', 'like', $prefix.'%')->whereNotNull('patent_application_id')->value('patent_application_id')
            : null;
        $appId = $parentAppId ?: $siblingAppId ?: PatentApplication::create([
            'invention_family_id' => $family->id,
            'client_id' => $project->client_id,
            'application_number' => $project->application_number,
            'title' => $project->invention_title ?: $project->project_name,
            'priority_date' => $project->priority_date,
            'filing_date' => $project->filing_date,
            'legal_status' => 'Pending',
            'jurisdiction' => $project->patent_office_code ?: 'IN',
        ])->id;
        $project->update(['patent_application_id' => $appId]);
    }

    /**
     * What a change to $office/$service would do, without saving anything.
     *
     * @return array{docket: ?string, docket_error: ?string, workflow: string, stages: list<array{name: string, status: string}>, lands_on: ?string}
     */
    public function preview(Project $project, string $office, string $service): array
    {
        $docket = null;
        $docketError = null;
        try {
            $docket = app(DocketNumberService::class)->recanonicalize($project, $office, $service)['docket_number'];
        } catch (ValidationException $e) {
            $docketError = collect($e->errors())->flatten()->first();
        }

        $result = null;
        DB::beginTransaction();
        try {
            $copy = Project::findOrFail($project->id);
            $copy->forceFill(['patent_office_code' => strtoupper($office), 'service_code' => strtoupper($service)])->save();
            $this->rebuild($copy, $copy->stages()->orderBy('sequence_order')->get());
            $stages = $copy->stages()->orderBy('sequence_order')->get();
            $result = [
                'workflow' => $copy->lifecycleTemplate ? "{$copy->lifecycleTemplate->name} ({$copy->lifecycleTemplate->version})" : 'Standard '.strtoupper($service).' stages',
                'stages' => $stages->map(fn ($s) => ['name' => $s->stage_name, 'status' => $s->status])->values()->all(),
                'lands_on' => $stages->firstWhere('status', 'In Progress')?->stage_name,
            ];
        } finally {
            DB::rollBack();
        }

        return ['docket' => $docket, 'docket_error' => $docketError, ...$result];
    }

    /**
     * Rebuilds the workflow after the case's service (and docket) have been saved.
     *
     * @return array<string, mixed> what changed (for the audit log / command output)
     */
    public function apply(Project $project, string $fromService, ?string $fromDocket, ?int $userId, string $reason): array
    {
        $summary = DB::transaction(function () use ($project, $fromService, $fromDocket, $userId, $reason) {
            $old = $project->stages()->orderBy('sequence_order')->get();
            $tag = 'svc:'.strtoupper($fromService).'>'.strtoupper((string) $project->service_code).':'.now()->format('ymdHis');   // ≤ 32 chars
            DB::table('project_stage_migration_snapshots')->insert([
                'project_id' => $project->id,
                'migration_version' => $tag,
                'previous_stages' => json_encode($old->map->getAttributes()->values()),
                'mapping_summary' => json_encode([
                    'from_template_id' => $project->getOriginal('jurisdiction_lifecycle_template_id'),
                    'from_service' => strtoupper($fromService), 'to_service' => strtoupper((string) $project->service_code),
                    'old_count' => $old->count(), 'old_completed' => $old->where('status', 'Completed')->count(),
                    'method' => 'intake_carry', 'reason' => $reason,
                ]),
                'created_at' => now(),
            ]);

            $this->rebuild($project, $old);
            $this->linkPatentApplication($project->fresh());

            if ($fromDocket && $fromDocket !== $project->docket_number) {
                TrackerRow::where('project_id', $project->id)->where('docket_number', $fromDocket)->update(['docket_number' => $project->docket_number]);
            }

            $landed = $project->stages()->where('status', 'In Progress')->value('stage_name');
            $summary = [
                'from_service' => strtoupper($fromService), 'to_service' => strtoupper((string) $project->service_code),
                'from_docket' => $fromDocket, 'to_docket' => $project->docket_number,
                'template' => $project->fresh()->lifecycle_template_version, 'lands_on' => $landed,
                'stages' => $project->stages()->count(), 'snapshot' => $tag, 'reason' => $reason,
            ];
            AuditLog::create([
                'user_id' => $userId, 'action' => 'service_changed',
                'subject_type' => 'Project', 'subject_id' => $project->id, 'metadata' => $summary,
            ]);

            return $summary;
        });

        app(ForeignFilingPermission::class)->sync($project->fresh());

        return $summary;
    }

    /** Delete the stages, seed the current service's workflow, then carry progress over. */
    private function rebuild(Project $project, Collection $old): void
    {
        $project->stages()->delete();
        $this->seed($project);
        $new = $project->stages()->orderBy('sequence_order')->get()->values();
        if ($new->isEmpty()) {
            return;
        }

        if (in_array($project->status, self::DONE_STATUSES, true)) {
            foreach ($new as $stage) {
                $stage->update(['status' => 'Completed', 'actual_start_at' => $stage->actual_start_at ?? now(), 'actual_end_at' => $stage->actual_end_at ?? now()]);
            }

            return;
        }

        $oldByName = $old->keyBy(fn ($s) => mb_strtolower(trim($s->stage_name)));
        $oldIntake = $old->filter(fn ($s) => preg_match(self::INTAKE_OLD, $s->stage_name) && in_array($s->status, ['Completed', 'In Progress'], true));
        $intakeStart = $old->first()?->actual_start_at ?? $oldIntake->first()?->actual_start_at;
        $intakeEnd = $oldIntake->last()?->actual_end_at ?? $oldIntake->last()?->actual_start_at;

        // Leading run of stages that count as done.
        $done = 0;
        foreach ($new as $index => $stage) {
            $same = $oldByName->get(mb_strtolower(trim($stage->stage_name)));
            $isIntake = $index === $done && preg_match(self::INTAKE_NEW, $stage->stage_name) && $oldIntake->isNotEmpty();
            if (($same && $same->status === 'Completed') || $isIntake) {
                $done++;
            } else {
                break;
            }
        }

        foreach ($new as $index => $stage) {
            $same = $oldByName->get(mb_strtolower(trim($stage->stage_name)));
            if ($index < $done) {
                $stage->update([
                    'status' => 'Completed',
                    'actual_start_at' => $same?->actual_start_at ?? $intakeStart ?? now(),
                    'actual_end_at' => $same?->actual_end_at ?? $intakeEnd ?? now(),
                ]);
            } elseif ($index === $done) {
                $stage->update(['status' => 'In Progress', 'actual_start_at' => $same?->actual_start_at ?? now(), 'actual_end_at' => null]);
            } else {
                $stage->update(['status' => 'Pending', 'actual_start_at' => null, 'actual_end_at' => null]);
            }
        }
    }
}
