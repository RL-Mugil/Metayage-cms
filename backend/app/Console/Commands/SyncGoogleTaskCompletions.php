<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Support\Notifier;
use Illuminate\Console\Command;

class SyncGoogleTaskCompletions extends Command
{
    protected $signature   = 'google:sync-completions';
    protected $description = 'Pull task completions from Google Tasks back into IPFlow';

    public function handle(GoogleCalendarService $svc): void
    {
        $users = User::whereNotNull('google_calendar_token')->get();

        foreach ($users as $user) {
            $completedIds = $svc->getCompletedTaskIds($user);
            if (empty($completedIds)) continue;

            // ── IPFlow Tasks ──────────────────────────────────────────────────
            Task::where('assignee_id', $user->id)
                ->whereNotNull('google_task_id')
                ->whereNotIn('status', ['Completed', 'Cancelled'])
                ->whereIn('google_task_id', $completedIds)
                ->each(function (Task $task) use ($user) {
                    $task->update(['status' => 'Completed']);
                    $this->line("  Task #{$task->id} '{$task->title}' → Completed (via Google Tasks, user {$user->id})");

                    // Notify the person who assigned the task
                    if ($task->assigned_by_id && $task->assigned_by_id !== $user->id) {
                        Notifier::push(
                            $task->assigned_by_id,
                            'task_completed',
                            'Task completed via Google Tasks',
                            "{$user->name} marked \"{$task->title}\" complete.",
                            '/tasks',
                            ['task_id' => $task->id],
                        );
                    }
                });

            // ── Project deadlines ─────────────────────────────────────────────
            // Only auto-complete the project when the partner or manager signs it off.
            // Engineers completing the deadline task triggers a notification instead.
            $userKey = (string) $user->id;

            Project::whereNotIn('status', ['Completed', 'Closed', 'Archived'])
                ->whereNotNull('google_task_ids')
                ->get()
                ->filter(fn (Project $p) => isset(($p->google_task_ids ?? [])[$userKey]))
                ->filter(fn (Project $p) => in_array($p->google_task_ids[$userKey], $completedIds))
                ->each(function (Project $project) use ($user) {
                    $isDecisionMaker = in_array($user->id, array_filter([
                        $project->assigned_partner_id,
                        $project->assigned_manager_id,
                    ]));

                    if ($isDecisionMaker) {
                        $project->update(['status' => 'Completed']);
                        $this->line("  Project #{$project->id} '{$project->docket_number}' → Completed (partner/manager signed off via Google Tasks)");

                        // Notify the rest of the team
                        $notifyIds = array_filter(array_unique([
                            $project->assigned_partner_id,
                            $project->assigned_manager_id,
                            $project->patent_engineer_id,
                        ]), fn ($id) => $id && $id !== $user->id);

                        foreach ($notifyIds as $uid) {
                            Notifier::push(
                                $uid,
                                'system',
                                'Case marked complete',
                                "{$user->name} completed {$project->docket_number} via Google Tasks.",
                                "/projects?open={$project->id}",
                                ['project_id' => $project->id],
                            );
                        }
                    } else {
                        // Engineer acknowledged — notify the partner/manager to review
                        $notifyIds = array_filter(array_unique([
                            $project->assigned_partner_id,
                            $project->assigned_manager_id,
                        ]), fn ($id) => $id && $id !== $user->id);

                        foreach ($notifyIds as $uid) {
                            Notifier::push(
                                $uid,
                                'system',
                                'Deadline acknowledged',
                                "{$user->name} marked the deadline for {$project->docket_number} complete in Google Tasks. Review and close the case if done.",
                                "/projects?open={$project->id}",
                                ['project_id' => $project->id],
                            );
                        }

                        $this->line("  Project #{$project->id} '{$project->docket_number}' — deadline acknowledged by {$user->name}, notified managers.");
                    }
                });
        }

        $this->info('Google Task completion sync done.');
    }
}
