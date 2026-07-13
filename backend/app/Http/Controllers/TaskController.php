<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\AuditLog;
use App\Http\PaginationHelper;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use App\Services\GoogleCalendarService;

class TaskController extends Controller
{
    public function inertiaIndex(Request $request)
    {
        return Inertia::render('Tasks');
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Task::with('project', 'assignee');

        if ($user->isClientRole()) {
            $client = $request->attributes->get('portal_client') ?? \App\Models\Client::forUser($user);
            if ($client) {
                $query->whereHas('project', fn ($q) => $q->where('client_id', $client->id));
            } else {
                $query->whereRaw('1=0');
            }
        } elseif ($user->role === 'associate') {
            // Patent Analysts see their assigned tasks
            $query->where('assignee_id', $user->id);
        } elseif ($user->isGalvanizer()) {
            $query->whereHas('project', fn ($q) => $user->applyProjectScope($q));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $query->orderBy('due_date');
        return response()->json(PaginationHelper::paginate($query, $request));
    }

    public function store(StoreTaskRequest $request)
    {
        $user = $request->user();
        $this->authorize('create', \App\Models\Task::class);
        $validated = $request->validated();

        if ($user->isGalvanizer()) {
            $project = Project::findOrFail($validated['project_id']);
            if (! $user->canAccessCircle($project->circle)) {
                return response()->json(['message' => 'Selected case is outside your assigned circle.'], 403);
            }
        }

        $validated['assignee_id']   = $validated['assignee_id'] ?? $user->id;
        $validated['reviewer_id']   = $validated['reviewer_id'] ?? $user->id;
        $validated['assigned_by_id'] = $user->id;
        $validated['status'] = 'Pending';

        $task = Task::create($validated);

        // In-app notification for the assignee (skip if assigning to self)
        if ($task->assignee_id && $task->assignee_id !== $user->id) {
            \App\Support\Notifier::push(
                $task->assignee_id,
                'task_assigned',
                'Task Assigned',
                "{$user->name} assigned you: {$task->title}",
                '/tasks',
                ['task_id' => $task->id],
            );
        }

        // Audit Log
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'create',
            'subject_type' => 'Task',
            'subject_id' => $task->id,
            'metadata' => ['title' => $task->title],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Sync to Google Tasks if assignee has connected their Google account
        if ($task->due_date && $task->assignee_id) {
            rescue(fn () => app(GoogleCalendarService::class)->syncTaskItem($task->fresh()->load('assignee', 'project')));
        }

        Cache::increment('dashboard_v');
        return response()->json($task, 201);
    }

    public function update(UpdateTaskRequest $request, $id)
    {
        $user = $request->user();
        $task = Task::findOrFail($id);
        $this->authorize('update', $task);
        $validated = $request->validated();

        $previousAssignee = $task->assignee_id;
        $previousStatus   = $task->status;
        $previousDue      = $task->due_date ? $task->due_date->toDateString() : null;
        $previousGTaskId  = $task->google_task_id;

        $task->update($validated);

        // Notify the new assignee when a task is reassigned to someone else.
        if (array_key_exists('assignee_id', $validated)
            && $task->assignee_id
            && (int) $task->assignee_id !== (int) $previousAssignee
            && (int) $task->assignee_id !== (int) $user->id) {
            \App\Support\Notifier::push(
                $task->assignee_id,
                'task_assigned',
                'Task reassigned to you',
                "{$user->name} reassigned you: {$task->title}",
                '/tasks',
                ['task_id' => $task->id],
            );
        }

        // Audit Log
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'update',
            'subject_type' => 'Task',
            'subject_id' => $task->id,
            'metadata' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $freshTask   = $task->fresh()->load('assignee', 'project');
        $gcalService = app(GoogleCalendarService::class);

        $nowCompleted  = $freshTask->status === 'Completed' && $previousStatus !== 'Completed';
        $dueChanged    = array_key_exists('due_date', $validated)
            && ($freshTask->due_date ? $freshTask->due_date->toDateString() : null) !== $previousDue;
        $assigneeSwapped = array_key_exists('assignee_id', $validated)
            && (int) $freshTask->assignee_id !== (int) $previousAssignee;

        rescue(function () use ($gcalService, $freshTask, $nowCompleted, $dueChanged, $assigneeSwapped, $previousAssignee, $previousGTaskId) {
            if ($nowCompleted) {
                // Mark complete in Google Tasks for the assignee
                if ($previousGTaskId && $freshTask->assignee) {
                    $gcalService->markGoogleTaskComplete($freshTask->assignee, $previousGTaskId);
                }
                return;
            }

            if ($assigneeSwapped && $previousGTaskId) {
                // Remove old assignee's task; new assignee gets one below
                $oldAssignee = \App\Models\User::find($previousAssignee);
                if ($oldAssignee) $gcalService->deleteTask($oldAssignee, $previousGTaskId);
                $freshTask->google_task_id = null;
                $freshTask->saveQuietly();
            }

            if ($dueChanged || $assigneeSwapped) {
                $gcalService->syncTaskItem($freshTask);
            }
        });

        Cache::increment('dashboard_v');
        return response()->json($task);
    }

    public function addTimeEntry(Request $request)
    {
        $user = $request->user();
        if ($user->isClientRole()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'task_id' => 'nullable|exists:tasks,id',
            'duration_hours' => 'required|numeric|min:0.1',
            'entry_date' => 'required|date',
            'description' => 'required|string',
            'billable' => 'boolean',
        ]);

        if (! in_array($user->role, ['super_admin', 'partner', 'manager'])) {
            $project = Project::findOrFail($validated['project_id']);
            if ($user->isGalvanizer()) {
                if (! $user->canAccessCircle($project->circle)) {
                    return response()->json(['message' => 'Selected case is outside your assigned circle.'], 403);
                }
            } else {
            $isTeamMember = $project->assigned_manager_id === $user->id
                || $project->assigned_partner_id === $user->id
                || $project->patent_engineer_id === $user->id
                || $project->tasks()->where('assignee_id', $user->id)->exists();
            if (! $isTeamMember) {
                return response()->json(['message' => 'You are not assigned to this project.'], 403);
            }
            }
        }

        if (! empty($validated['task_id'])) {
            $task = Task::findOrFail($validated['task_id']);
            if ($task->project_id !== (int) $validated['project_id']) {
                return response()->json(['message' => 'The task does not belong to the selected project.'], 422);
            }
        }

        $validated['user_id'] = $user->id;
        $validated['status'] = 'Draft';

        $entry = TimeEntry::create($validated);

        // Increment actual hours on the task if present
        if ($entry->task_id) {
            $task = Task::find($entry->task_id);
            $task->increment('actual_hours', $entry->duration_hours);
        }

        // Audit Log
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'time_log',
            'subject_type' => 'TimeEntry',
            'subject_id' => $entry->id,
            'metadata' => ['duration_hours' => $entry->duration_hours, 'project_id' => $entry->project_id],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json($entry, 201);
    }

    public function destroy(Request $request, $id)
    {
        $task = Task::findOrFail($id);
        $this->authorize('delete', $task);

        if ($task->google_task_id && $task->assignee_id) {
            rescue(fn () => app(GoogleCalendarService::class)->removeTaskItem($task->load('assignee')));
        }

        $task->delete();
        Cache::increment('dashboard_v');
        return response()->json(['message' => 'Task deleted']);
    }
}
