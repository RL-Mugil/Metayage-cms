<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecycleBinController extends Controller
{
    private const VIEW_ROLES   = ['super_admin', 'partner', 'manager'];
    private const RESTORE_ROLES = ['super_admin', 'partner'];
    private const HARD_ROLES   = ['super_admin'];

    public function index(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, self::VIEW_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $projects = Project::onlyTrashed()
            ->with(['client:id,client_code,company_name,legal_name'])
            ->select(['id', 'project_code', 'docket_number', 'project_type', 'client_id', 'status', 'deleted_at'])
            ->orderByDesc('deleted_at')
            ->get();

        $clients = Client::onlyTrashed()
            ->select(['id', 'client_code', 'legal_name', 'company_name', 'client_type', 'status', 'deleted_at'])
            ->orderByDesc('deleted_at')
            ->get();

        $documents = Document::onlyTrashed()
            ->select(['id', 'file_name', 'category', 'client_id', 'project_id', 'storage_path', 'deleted_at'])
            ->orderByDesc('deleted_at')
            ->get();

        return response()->json([
            'projects'  => $projects,
            'clients'   => $clients,
            'documents' => $documents,
        ]);
    }

    public function restore(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, self::RESTORE_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'type' => 'required|in:project,client,document',
            'id'   => 'required|integer',
        ]);

        DB::transaction(function () use ($validated, $user, $request) {
            $model = $this->findTrashed($validated['type'], $validated['id']);
            $model->restore();

            AuditLog::create([
                'user_id'      => $user->id,
                'action'       => 'restore',
                'subject_type' => ucfirst($validated['type']),
                'subject_id'   => $validated['id'],
                'metadata'     => ['type' => $validated['type']],
                'ip_address'   => $request->ip(),
                'user_agent'   => $request->userAgent(),
            ]);
        });

        return response()->json(['message' => ucfirst($validated['type']) . ' restored successfully.']);
    }

    public function hardDelete(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, self::HARD_ROLES)) {
            return response()->json(['message' => 'Forbidden — only super_admin can permanently delete'], 403);
        }

        $validated = $request->validate([
            'type' => 'required|in:project,client,document',
            'id'   => 'required|integer',
        ]);

        DB::transaction(function () use ($validated, $user, $request) {
            $model = $this->findTrashed($validated['type'], $validated['id']);

            AuditLog::create([
                'user_id'      => $user->id,
                'action'       => 'hard_delete',
                'subject_type' => ucfirst($validated['type']),
                'subject_id'   => $validated['id'],
                'metadata'     => ['type' => $validated['type'], 'permanent' => true],
                'ip_address'   => $request->ip(),
                'user_agent'   => $request->userAgent(),
            ]);

            $model->forceDelete();
        });

        return response()->json(['message' => ucfirst($validated['type']) . ' permanently deleted.']);
    }

    public function bulkRestore(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, self::RESTORE_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'type' => 'required|in:project,client,document',
            'ids'  => 'required|array|min:1',
            'ids.*'=> 'integer',
        ]);

        $type = $validated['type'];
        $ids  = $validated['ids'];
        $restored = 0;

        DB::transaction(function () use ($type, $ids, $user, $request, &$restored) {
            foreach ($ids as $id) {
                $model = $this->findTrashed($type, (int) $id);
                $model->restore();
                $restored++;
                AuditLog::create([
                    'user_id'      => $user->id,
                    'action'       => 'restore',
                    'subject_type' => ucfirst($type),
                    'subject_id'   => $id,
                    'metadata'     => ['type' => $type, 'bulk' => true],
                    'ip_address'   => $request->ip(),
                    'user_agent'   => $request->userAgent(),
                ]);
            }
        });

        return response()->json(['message' => "{$restored} " . ucfirst($type) . "(s) restored.", 'restored' => $restored]);
    }

    public function bulkHardDelete(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, self::HARD_ROLES)) {
            return response()->json(['message' => 'Forbidden — only super_admin can permanently delete'], 403);
        }

        $validated = $request->validate([
            'type' => 'required|in:project,client,document',
            'ids'  => 'required|array|min:1',
            'ids.*'=> 'integer',
        ]);

        $type    = $validated['type'];
        $ids     = $validated['ids'];
        $deleted = 0;

        DB::transaction(function () use ($type, $ids, $user, $request, &$deleted) {
            foreach ($ids as $id) {
                $model = $this->findTrashed($type, (int) $id);
                AuditLog::create([
                    'user_id'      => $user->id,
                    'action'       => 'hard_delete',
                    'subject_type' => ucfirst($type),
                    'subject_id'   => $id,
                    'metadata'     => ['type' => $type, 'permanent' => true, 'bulk' => true],
                    'ip_address'   => $request->ip(),
                    'user_agent'   => $request->userAgent(),
                ]);
                $model->forceDelete();
                $deleted++;
            }
        });

        return response()->json(['message' => "{$deleted} " . ucfirst($type) . "(s) permanently deleted.", 'deleted' => $deleted]);
    }

    private function findTrashed(string $type, int $id)
    {
        return match ($type) {
            'project'  => Project::onlyTrashed()->findOrFail($id),
            'client'   => Client::onlyTrashed()->findOrFail($id),
            'document' => Document::onlyTrashed()->findOrFail($id),
        };
    }
}
