<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BulkController extends Controller
{
    private const MANAGE_ROLES = ['super_admin', 'partner', 'manager'];

    /** Per-entity allowed status values for bulk Change Status. */
    private const STATUS_WHITELIST = [
        'clients' => ['Active', 'Inactive', 'Prospect', 'On Hold'],
        'projects' => ['Active', 'On Hold', 'Completed', 'Archived'],
        'tasks' => ['Pending', 'In Progress', 'Completed', 'Archived'],
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
        $ids = $validated['ids'];

        $model = match ($entity) {
            'clients' => Client::class,
            'projects' => Project::class,
            'tasks' => Task::class,
        };

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
                $affected = $model::whereIn('id', $ids)->count();
                DB::table('ip_notifications')->insert([
                    'user_id' => $request->user()->id,
                    'type' => 'bulk_notify',
                    'title' => 'Bulk notification queued',
                    'description' => "Notification queued for {$affected} {$entity}",
                    'meta' => json_encode(['entity' => $entity, 'ids' => $ids]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                break;
        }

        return response()->json(['ok' => true, 'affected' => $affected]);
    }
}
