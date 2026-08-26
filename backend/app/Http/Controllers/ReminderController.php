<?php

namespace App\Http\Controllers;

use App\Http\PaginationHelper;
use App\Http\Requests\StoreReminderRequest;
use App\Http\Requests\UpdateReminderRequest;
use App\Http\Resources\ReminderResource;
use App\Models\AuditLog;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\FirmContext;

class ReminderController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Reminder::class);
        $user = $request->user();
        $query = Reminder::with('assignee:id,name')->where(function ($query) use ($user): void {
            $query->where('user_id', $user->id)->orWhere('assigned_user_id', $user->id);
            if (in_array($user->role, ['super_admin', 'partner', 'manager'], true)) $query->orWhere('scope', 'team');
        })->orderBy('due_date')->orderBy('due_time');

        if ($request->filled('completed')) $query->where('completed', $request->boolean('completed'));
        if ($request->filled('category')) $query->where('category', $request->string('category'));

        $result = PaginationHelper::paginate($query, $request);
        $result['data'] = ReminderResource::collection($result['data'])->resolve($request);
        return response()->json($result);
    }

    public function store(StoreReminderRequest $request)
    {
        $validated = $request->validated();
        $assignee = isset($validated['assigned_user_id']) ? User::findOrFail($validated['assigned_user_id']) : null;
        abort_if($assignee && $assignee->isClientRole(), 422, 'Reminders may only be assigned to staff users.');
        abort_if($assignee && ! $assignee->firms()->whereKey(app(FirmContext::class)->id())->exists(), 422, 'The assignee is not a member of the active firm.');

        $reminder = DB::transaction(function () use ($request, $validated): Reminder {
            $reminder = Reminder::create($validated + ['user_id' => $request->user()->id]);
            $this->audit($request, 'create', $reminder, ['scope' => $reminder->scope, 'assigned_user_id' => $reminder->assigned_user_id]);
            return $reminder;
        });
        return (new ReminderResource($reminder->load('assignee:id,name')))->response()->setStatusCode(201);
    }

    public function update(UpdateReminderRequest $request, $id)
    {
        $reminder = Reminder::findOrFail($id);
        $this->authorize('update', $reminder);
        $validated = $request->validated();
        DB::transaction(function () use ($request, $reminder, $validated): void {
            $changes = [];
            if (array_key_exists('completed', $validated)) $changes['completed'] = $validated['completed'];
            if (array_key_exists('acknowledged', $validated)) {
                $changes['acknowledged_at'] = $validated['acknowledged'] ? now() : null;
                $changes['acknowledged_by'] = $validated['acknowledged'] ? $request->user()->id : null;
            }
            $reminder->update($changes);
            $this->audit($request, 'update', $reminder, $changes);
        });
        return new ReminderResource($reminder->fresh('assignee:id,name'));
    }

    public function destroy(Request $request, $id)
    {
        $reminder = Reminder::findOrFail($id);
        $this->authorize('delete', $reminder);
        DB::transaction(function () use ($request, $reminder): void {
            $this->audit($request, 'delete', $reminder, ['title' => $reminder->title]);
            $reminder->delete();
        });
        return response()->json(['message' => 'Reminder deleted']);
    }

    public function helpRequest(Request $request, $id)
    {
        $validated = $request->validate(['target_user_id' => ['required', 'integer', 'exists:users,id'], 'note' => ['nullable', 'string', 'max:500']]);
        $reminder = Reminder::findOrFail($id);
        $this->authorize('view', $reminder);
        abort_if(User::findOrFail($validated['target_user_id'])->isClientRole(), 422, 'Help requests may only be sent to staff users.');
        abort_unless(User::findOrFail($validated['target_user_id'])->firms()->whereKey(app(FirmContext::class)->id())->exists(), 422, 'The selected user is not a member of the active firm.');
        DB::transaction(function () use ($request, $reminder, $validated): void {
            $note = filled($validated['note'] ?? null) ? ' — "'.$validated['note'].'"' : '';
            DB::table('ip_notifications')->insert([
                'user_id' => $validated['target_user_id'], 'type' => 'help_request', 'title' => "Help requested: {$reminder->title}",
                'description' => $request->user()->name." needs help with a reminder{$note}", 'action_url' => '/reminders',
                'meta' => json_encode(['reminder_id' => $reminder->id, 'requester_id' => $request->user()->id]), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($request, 'help_request', $reminder, ['target_user_id' => $validated['target_user_id']]);
        });
        return response()->json(['ok' => true, 'message' => 'Help request sent.']);
    }

    private function audit(Request $request, string $action, Reminder $reminder, array $metadata): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => "reminder_{$action}", 'subject_type' => 'Reminder',
            'subject_id' => $reminder->id, 'metadata' => $metadata, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
    }
}
