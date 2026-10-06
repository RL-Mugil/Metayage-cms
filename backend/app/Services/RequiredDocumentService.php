<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Support\Notifier;

/**
 * Tracks "a document must be uploaded" requirements that a user can skip in the moment —
 * the Grant/Refusal Order (status-change popup, ProjectController::update()) and the
 * stage-gated draft/forms documents (ProjectController::STAGE_DOCUMENT_REQUIRED). A single
 * open Task per (project, category), tagged via `tags.required_document_category`, is the
 * source of truth for "still pending" — SendRequiredDocumentRemindersCommand re-notifies
 * daily until a matching Document is uploaded, at which point DocumentController::store()
 * resolves (completes) it.
 */
class RequiredDocumentService
{
    /** Creates the tracking Task + fires the first notification, unless one is already open. */
    public function ensure(Project $project, string $category, string $contextLabel, ?int $actorId): Task
    {
        $existing = $this->openTaskFor($project->id, $category);
        if ($existing) {
            return $existing;
        }

        $assigneeId = $project->patent_engineer_id ?? $project->assigned_manager_id;
        $task = Task::create([
            'project_id'     => $project->id,
            'title'          => "Upload {$contextLabel} — {$project->docket_number}",
            'assignee_id'    => $assigneeId,
            'assigned_by_id' => $actorId,
            'priority'       => 'Normal',
            'due_date'       => now(),
            'status'         => 'Not Started',
            'tags'           => ['required_document_category' => $category, 'context_label' => $contextLabel],
        ]);

        $this->notify($project, $task, $category, $contextLabel);

        return $task;
    }

    public function notify(Project $project, Task $task, string $category, string $contextLabel): void
    {
        if (! $task->assignee_id) {
            return;
        }
        Notifier::push(
            $task->assignee_id,
            'document_required',
            'Document upload needed',
            "{$contextLabel} — {$project->docket_number} needs a document upload.",
            "/projects?open={$project->id}&upload_category=" . urlencode($category),
            ['project_id' => $project->id, 'task_id' => $task->id, 'category' => $category]
        );
    }

    /** Called after a Document is stored (DocumentController::store()) — completes any matching tracking Task. */
    public function resolve(?int $projectId, ?string $category): void
    {
        if (! $projectId || ! $category) {
            return;
        }
        $task = $this->openTaskFor($projectId, $category);
        $task?->update(['status' => 'Completed']);
    }

    private function openTaskFor(int $projectId, string $category): ?Task
    {
        return Task::where('project_id', $projectId)
            ->where('status', '!=', 'Completed')
            ->where('tags->required_document_category', $category)
            ->first();
    }
}
