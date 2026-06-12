<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\AuditLog;
use App\Http\PaginationHelper;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

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

        if ($user->role === 'client') {
            $query->whereHas('project.client.contacts', function ($q) use ($user) {
                $q->where('email', $user->email);
            });
        } elseif (in_array($user->role, ['associate', 'paralegal'])) {
            // Associates see their assigned tasks
            $query->where('assignee_id', $user->id);
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
        $validated = $request->validated();

        $validated['assignee_id'] = $validated['assignee_id'] ?? $user->id;
        $validated['reviewer_id'] = $validated['reviewer_id'] ?? $user->id;
        $validated['status'] = 'Pending';

        $task = Task::create($validated);

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

        return response()->json($task, 201);
    }

    public function update(UpdateTaskRequest $request, $id)
    {
        $user = $request->user();
        $task = Task::findOrFail($id);
        $validated = $request->validated();

        $task->update($validated);

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

        return response()->json($task);
    }

    public function addTimeEntry(Request $request)
    {
        $user = $request->user();
        if ($user->role === 'client') {
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
        $user = $request->user();
        $task = Task::findOrFail($id);

        if (! in_array($user->role, ['super_admin', 'partner', 'manager']) && $task->assignee_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $task->delete();
        return response()->json(['message' => 'Task deleted']);
    }
}
