<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectStage;
use Carbon\Carbon;

/**
 * Advances a project's own "Prosecution lifecycle" stages (see ProjectStage)
 * in response to a docket event recorded directly against it, so the
 * client-visible stepper reflects reality without a manual step.
 *
 * Only ever touches $project's own stages — never a sibling engagement's —
 * because each Project row runs its own service-specific lifecycle template
 * (a FER response's stages are about responding to the FER, not about the
 * whole case). Cross-project visibility of the underlying docket event
 * itself is handled separately, by DocketEvent/DocketDeadline sharing a
 * patent_application_id (see ApplicationNumberSyncService).
 *
 * A stage opts in to auto-advancement by listing the triggering event
 * type(s) in its gate_criteria: {"advances_on_event_types": ["fer_received"]}.
 * Stages with no such criteria (the majority — most stages have no single
 * docket event that proves them done) are left to their existing manual
 * advance path (ProjectController::updateStage) entirely unchanged.
 */
class StageAdvancementService
{
    public function advance(Project $project, string $eventType, Carbon $eventDate): void
    {
        $stage = $project->stages()
            ->whereJsonContains('gate_criteria->advances_on_event_types', $eventType)
            ->where('status', '!=', 'Completed')
            ->first();

        if (! $stage) {
            return;
        }

        // This stage, and any earlier stage still open, are proven complete
        // by the event having happened at all.
        $project->stages()
            ->where('sequence_order', '<=', $stage->sequence_order)
            ->where('status', '!=', 'Completed')
            ->update(['status' => 'Completed', 'actual_end_at' => $eventDate]);

        $next = $project->stages()->where('sequence_order', '>', $stage->sequence_order)->first();
        if ($next && $next->status === 'Pending') {
            $next->update(['status' => 'In Progress', 'actual_start_at' => $eventDate]);
        }
    }
}
