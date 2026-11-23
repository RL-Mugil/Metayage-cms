<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves an existing case onto the lifecycle template that now fits its office and service
 * (e.g. a US case still on the generic US list after the US templates per service code
 * were added). The old stages are kept in project_stage_migration_snapshots; progress is
 * carried over by completed ratio, the same method as the 2026.1 backfill.
 */
class LifecycleReapplyService
{
    public function __construct(private readonly JurisdictionLifecycleService $lifecycles) {}

    /**
     * @return array{project_id: int, from: ?string, to: string, old_completed: int, new_completed: int}|null
     *         null when the case is already on the right template or none fits
     */
    public function plan(Project $project): ?array
    {
        try {
            $template = $this->lifecycles->resolve($project->patent_office_code ?: 'IN', (string) $project->service_code);
        } catch (ValidationException) {
            return null;
        }
        if ((int) $project->jurisdiction_lifecycle_template_id === (int) $template->id || $template->stages->isEmpty()) {
            return null;
        }

        $old = $project->stages()->orderBy('sequence_order')->get();
        $done = $old->where('status', 'Completed')->count();
        $total = $template->stages->count();
        $mapped = in_array($project->status, ['Completed', 'Granted', 'Closed'], true)
            ? $total
            : ($old->isEmpty() ? 0 : min($total - 1, (int) round($done / $old->count() * $total)));

        return [
            'project_id' => $project->id,
            'from' => $project->lifecycleTemplate ? "{$project->lifecycleTemplate->name} ({$project->lifecycleTemplate->version})" : null,
            'to' => "{$template->name} ({$template->version})",
            'old_completed' => $done,
            'new_completed' => $mapped,
            'template' => $template,
        ];
    }

    public function reapply(Project $project, string $migrationVersion, ?int $userId = null): ?array
    {
        $plan = $this->plan($project);
        if (! $plan) {
            return null;
        }
        $template = $plan['template'];

        DB::transaction(function () use ($project, $plan, $template, $migrationVersion, $userId) {
            $old = $project->stages()->orderBy('sequence_order')->get();
            DB::table('project_stage_migration_snapshots')->updateOrInsert(
                ['project_id' => $project->id, 'migration_version' => $migrationVersion],
                [
                    'previous_stages' => json_encode($old->map->getAttributes()->values()),
                    'mapping_summary' => json_encode([
                        'from_template_id' => $project->jurisdiction_lifecycle_template_id,
                        'to_template_id' => $template->id,
                        'old_count' => $old->count(), 'old_completed' => $plan['old_completed'],
                        'new_count' => $template->stages->count(), 'new_completed' => $plan['new_completed'],
                        'method' => 'completion_ratio',
                    ]),
                ]
            );

            // Completed dates carry over in order where the old list had them.
            $oldDone = $old->where('status', 'Completed')->values();
            $project->stages()->delete();
            foreach ($template->stages->sortBy('sequence_order')->values() as $index => $stage) {
                $status = $index < $plan['new_completed'] ? 'Completed'
                    : ($index === $plan['new_completed'] ? 'In Progress' : 'Pending');
                $source = $oldDone->get($index);
                ProjectStage::create([
                    'project_id' => $project->id,
                    'stage_name' => $stage->stage_name,
                    'status' => $status,
                    'sequence_order' => $index,
                    'duration_days' => $stage->target_duration_days ?? 0,
                    'gate_criteria' => $stage->gate_criteria,
                    'actual_start_at' => $status === 'Pending' ? null : ($source?->actual_start_at ?? now()),
                    'actual_end_at' => $status === 'Completed' ? ($source?->actual_end_at ?? now()) : null,
                ]);
            }

            $project->update([
                'jurisdiction_lifecycle_template_id' => $template->id,
                'lifecycle_template_version' => $template->version,
            ]);
            AuditLog::create([
                'user_id' => $userId,
                'action' => 'lifecycle_template_reapplied',
                'subject_type' => 'Project',
                'subject_id' => $project->id,
                'metadata' => ['migration_version' => $migrationVersion, 'from' => $plan['from'], 'to' => $plan['to'],
                    'old_completed' => $plan['old_completed'], 'new_completed' => $plan['new_completed']],
            ]);
        });

        app(ForeignFilingPermission::class)->sync($project->fresh());
        unset($plan['template']);

        return $plan;
    }
}
