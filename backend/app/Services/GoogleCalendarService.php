<?php

namespace App\Services;

use App\Models\User;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    private const AUTH_URI   = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URI  = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URI = 'https://oauth2.googleapis.com/revoke';
    private const TASKS_API  = 'https://tasks.googleapis.com/tasks/v1';

    // Tasks scope — separate from calendar.events
    private const SCOPES = [
        'https://www.googleapis.com/auth/tasks',
        'https://www.googleapis.com/auth/userinfo.email',
    ];

    public function buildAuthUrl(string $state): string
    {
        $params = http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => config('services.google.redirect'),
            'response_type' => 'code',
            'scope'         => implode(' ', self::SCOPES),
            'access_type'   => 'offline',
            'prompt'        => 'consent',  // force refresh_token every time
            'state'         => $state,
        ]);
        return self::AUTH_URI . '?' . $params;
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URI, [
            'code'          => $code,
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri'  => config('services.google.redirect'),
            'grant_type'    => 'authorization_code',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Google token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    /** Returns a valid access token, refreshing automatically if expired. */
    public function getAccessToken(User $user): ?string
    {
        $stored = json_decode($user->google_calendar_token ?? '{}', true);
        if (empty($stored['refresh_token'])) return null;

        if (! empty($stored['access_token']) && ($stored['expires_at'] ?? 0) > (time() + 60)) {
            return $stored['access_token'];
        }

        $response = Http::asForm()->post(self::TOKEN_URI, [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $stored['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]);

        if (! $response->successful()) {
            Log::warning('Google token refresh failed for user ' . $user->id, ['body' => $response->body()]);
            return null;
        }

        $tokens = $response->json();
        $stored['access_token'] = $tokens['access_token'];
        $stored['expires_at']   = time() + ($tokens['expires_in'] ?? 3600);

        $user->google_calendar_token = json_encode($stored);
        $user->save();

        return $tokens['access_token'];
    }

    public function getUserEmail(string $accessToken): ?string
    {
        $res = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v2/userinfo');
        return $res->successful() ? ($res->json('email') ?? null) : null;
    }

    /**
     * Create or update a task in the user's Google Tasks default list.
     * Returns the task ID on success, null on failure.
     */
    public function upsertTask(User $user, string $title, string $notes, string $dueDate, ?string $existingTaskId = null): ?string
    {
        $token = $this->getAccessToken($user);
        if (! $token) return null;

        // due must be RFC 3339 UTC midnight
        $due = $dueDate . 'T00:00:00.000Z';

        $body = [
            'title' => $title,
            'notes' => $notes,
            'due'   => $due,
        ];

        if ($existingTaskId) {
            $res = Http::withToken($token)
                ->patch(self::TASKS_API . "/lists/@default/tasks/{$existingTaskId}", $body);

            // If the task was deleted from Google side, fall through to create
            if ($res->status() === 404) {
                $existingTaskId = null;
            } elseif ($res->successful()) {
                return $res->json('id');
            }
        }

        if (! $existingTaskId) {
            $res = Http::withToken($token)
                ->post(self::TASKS_API . '/lists/@default/tasks', $body);

            if ($res->successful()) {
                return $res->json('id');
            }
        }

        Log::warning('Google Tasks upsert failed', [
            'user'   => $user->id,
            'status' => $res->status(),
            'body'   => $res->body(),
        ]);
        return null;
    }

    public function deleteTask(User $user, string $taskId): void
    {
        $token = $this->getAccessToken($user);
        if (! $token) return;

        Http::withToken($token)
            ->delete(self::TASKS_API . "/lists/@default/tasks/{$taskId}");
    }

    /**
     * Push (or update) deadline tasks for all assigned users on a project.
     * Saves task IDs back to project.google_task_ids.
     */
    public function syncProjectDeadline(Project $project): void
    {
        if (! $project->hard_deadline) return;

        $assigneeIds = array_filter([
            $project->assigned_partner_id,
            $project->assigned_manager_id,
            $project->patent_engineer_id,
        ]);

        if (empty($assigneeIds)) return;

        $users = User::whereIn('id', $assigneeIds)
            ->whereNotNull('google_calendar_token')
            ->get();

        if ($users->isEmpty()) return;

        $title = "⚖️ Deadline: {$project->docket_number}";
        $notes = implode("\n", array_filter([
            $project->title ?? $project->project_type,
            "Client: " . optional($project->client)->company_name,
            "Case type: {$project->case_type}",
            "Office: {$project->patent_office_code}",
            config('app.url') . "/projects?open={$project->id}",
        ]));

        $taskIds = $project->google_task_ids ?? [];

        foreach ($users as $user) {
            $existingId = $taskIds[(string) $user->id] ?? null;
            $newId = $this->upsertTask($user, $title, $notes, $project->hard_deadline, $existingId);
            if ($newId) {
                $taskIds[(string) $user->id] = $newId;
            }
        }

        $project->google_task_ids = $taskIds;
        $project->saveQuietly();
    }

    /**
     * Remove deadline tasks from all assignees (called when project completed/archived or deadline cleared).
     */
    public function removeProjectDeadline(Project $project): void
    {
        $taskIds = $project->google_task_ids ?? [];
        if (empty($taskIds)) return;

        $users = User::whereIn('id', array_keys($taskIds))
            ->whereNotNull('google_calendar_token')
            ->get()
            ->keyBy('id');

        foreach ($taskIds as $userId => $taskId) {
            $user = $users->get($userId);
            if ($user) $this->deleteTask($user, $taskId);
        }

        $project->google_task_ids = null;
        $project->saveQuietly();
    }

    /**
     * Sync an IPFlow Task's due date to Google Tasks for its assignee.
     * Stores the returned task ID back on the model.
     */
    public function syncTaskItem(Task $task): void
    {
        if (! $task->due_date || ! $task->assignee_id) return;

        $assignee = $task->relationLoaded('assignee') ? $task->assignee : User::find($task->assignee_id);
        if (! $assignee || empty($assignee->google_calendar_token)) return;

        $title = "📋 {$task->title}";
        $notes = implode("\n", array_filter([
            $task->description,
            $task->project ? "Project: {$task->project->docket_number}" : null,
            "Priority: {$task->priority}",
            config('app.url') . "/tasks?open={$task->id}",
        ]));

        $due    = $task->due_date instanceof \Carbon\Carbon
            ? $task->due_date->toDateString()
            : substr((string) $task->due_date, 0, 10);

        $newId = $this->upsertTask($assignee, $title, $notes, $due, $task->google_task_id);
        if ($newId && $newId !== $task->google_task_id) {
            $task->google_task_id = $newId;
            $task->saveQuietly();
        }
    }

    /**
     * Remove assignee's Google Task when a task is deleted or reassigned away.
     */
    public function removeTaskItem(Task $task): void
    {
        if (! $task->google_task_id || ! $task->assignee_id) return;
        $assignee = User::find($task->assignee_id);
        if ($assignee) $this->deleteTask($assignee, $task->google_task_id);
        $task->google_task_id = null;
        $task->saveQuietly();
    }

    /**
     * Mark a Google Task as complete (called when IPFlow task/project is completed).
     */
    public function markGoogleTaskComplete(User $user, string $taskId): void
    {
        $token = $this->getAccessToken($user);
        if (! $token) return;

        Http::withToken($token)->patch(
            self::TASKS_API . "/lists/@default/tasks/{$taskId}",
            ['status' => 'completed']
        );
    }

    /**
     * Return all Google Task IDs (in the user's @default list) that are marked completed.
     * Used by the completion-sync cron to pull status back into IPFlow.
     */
    public function getCompletedTaskIds(User $user): array
    {
        $token = $this->getAccessToken($user);
        if (! $token) return [];

        $ids = [];
        $pageToken = null;

        do {
            $params = ['showCompleted' => 'true', 'showHidden' => 'true', 'maxResults' => 100];
            if ($pageToken) $params['pageToken'] = $pageToken;

            $res = Http::withToken($token)->get(self::TASKS_API . '/lists/@default/tasks', $params);
            if (! $res->successful()) break;

            foreach ($res->json('items', []) as $item) {
                if (($item['status'] ?? '') === 'completed') {
                    $ids[] = $item['id'];
                }
            }

            $pageToken = $res->json('nextPageToken');
        } while ($pageToken);

        return $ids;
    }

    public function disconnect(User $user): void
    {
        $stored = json_decode($user->google_calendar_token ?? '{}', true);
        if (! empty($stored['access_token'])) {
            Http::asForm()->post(self::REVOKE_URI, ['token' => $stored['access_token']]);
        }
        $user->google_calendar_token = null;
        $user->google_calendar_email = null;
        $user->save();
    }
}
