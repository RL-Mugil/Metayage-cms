<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BulkController extends Controller
{
    private const MANAGE_ROLES = ['super_admin', 'partner', 'manager'];

    /** Per-entity allowed status values for bulk Change Status. */
    private const STATUS_WHITELIST = [
        'clients'  => ['Active', 'Inactive', 'Prospect', 'On Hold'],
        'projects' => ['Active', 'On Hold', 'Completed', 'Archived'],
        'tasks'    => ['Not Started', 'Pending', 'In Progress', 'Review', 'Awaiting Review', 'Completed', 'Cancelled', 'Blocked'],
    ];

    /** What "Archive" means per entity. */
    private const ARCHIVE_STATUS = [
        'clients' => 'Inactive',
        'projects' => 'Archived',
        'tasks' => 'Archived',
    ];

    public function execute(Request $request)
    {
        if (! in_array($request->user()->role, self::MANAGE_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'entity' => 'required|in:clients,projects,tasks',
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'action' => 'required|in:change_status,archive,notify',
            'status' => 'required_if:action,change_status|string',
        ]);

        $entity = $validated['entity'];
        $ids    = $validated['ids'];
        $user   = $request->user();

        $model = match ($entity) {
            'clients' => Client::class,
            'projects' => Project::class,
            'tasks' => Task::class,
        };

        // Managers may only operate on records they own; super_admin and partner are unrestricted.
        if ($user->role === 'manager') {
            $ids = match ($entity) {
                'clients'  => Client::whereIn('id', $ids)->where('account_manager_id', $user->id)->pluck('id')->all(),
                'projects' => Project::whereIn('id', $ids)
                    ->where(fn ($q) => $q->where('assigned_manager_id', $user->id)
                        ->orWhereJsonContains('assigned_team', $user->id))
                    ->pluck('id')->all(),
                'tasks'    => Task::whereIn('id', $ids)->whereHas('project', fn ($q) => $q->where('assigned_manager_id', $user->id))->pluck('id')->all(),
            };
            if (empty($ids)) {
                return response()->json(['message' => 'No records found within your scope.'], 403);
            }
        }

        $affected = 0;

        switch ($validated['action']) {
            case 'change_status':
                if (! in_array($validated['status'], self::STATUS_WHITELIST[$entity])) {
                    return response()->json(['message' => 'Status not allowed for this entity'], 422);
                }
                $affected = $model::whereIn('id', $ids)->update(['status' => $validated['status']]);
                break;

            case 'archive':
                $affected = $model::whereIn('id', $ids)->update(['status' => self::ARCHIVE_STATUS[$entity]]);
                break;

            case 'notify':
                // Resolve which user IDs should receive the notification.
                // Clients → their portal user accounts (matched by contact email).
                // Projects/Tasks → the assigned manager / assignee.
                $recipientUserIds = match ($entity) {
                    'clients' => DB::table('client_contacts')
                        ->whereIn('client_id', $ids)
                        ->join('users', 'users.email', '=', 'client_contacts.email')
                        ->pluck('users.id')
                        ->unique()->all(),
                    'projects' => Project::whereIn('id', $ids)
                        ->pluck('assigned_manager_id')
                        ->filter()->unique()->all(),
                    'tasks' => Task::whereIn('id', $ids)
                        ->pluck('assignee_id')
                        ->filter()->unique()->all(),
                };
                $affected = count($recipientUserIds);
                $rows = array_map(fn($uid) => [
                    'user_id'     => $uid,
                    'type'        => 'bulk_notify',
                    'title'       => 'You have been notified',
                    'description' => "A bulk notification was sent to you regarding {$entity} by {$request->user()->name}",
                    'meta'        => json_encode(['entity' => $entity, 'ids' => $ids, 'sent_by' => $request->user()->id]),
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ], $recipientUserIds);
                if ($rows) {
                    DB::table('ip_notifications')->insert($rows);
                }
                break;
        }

        Cache::increment('dashboard_v');
        return response()->json(['ok' => true, 'affected' => $affected]);
    }
}
