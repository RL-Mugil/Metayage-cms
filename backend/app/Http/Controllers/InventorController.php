<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Staff-side management of a case's inventors (project_inventors pivot).
 * Adding an inventor by an email that doesn't exist yet creates a new
 * `inventor`-role login for them — same "set a password, share it directly"
 * pattern as MyPortalController::store() (no auto-email).
 */
class InventorController extends Controller
{
    private function denyUnlessCanManage(Request $request, Project $project): ?JsonResponse
    {
        if (! $request->user()->can('update', $project)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    public function index(Request $request, $projectId)
    {
        $project = Project::findOrFail($projectId);
        $this->authorize('view', $project);

        return response()->json($project->inventors()->get(['users.id', 'users.name', 'users.email']));
    }

    public function store(Request $request, $projectId)
    {
        $project = Project::findOrFail($projectId);
        if ($deny = $this->denyUnlessCanManage($request, $project)) {
            return $deny;
        }

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255',
            'password' => 'nullable|string|max:100',
        ]);

        $inventor = User::where('email', $validated['email'])->first();
        if ($inventor) {
            if ($inventor->role !== 'inventor') {
                return response()->json(['message' => 'This email belongs to an existing account with a different role.'], 422);
            }
        } else {
            if (empty($validated['password']) || strlen($validated['password']) < 6) {
                return response()->json(['message' => 'A password (min. 6 characters) is required to create a new inventor login.'], 422);
            }
            $inventor = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role'     => 'inventor',
                'status'   => 'Active',
            ]);
        }

        $project->inventors()->syncWithoutDetaching([$inventor->id]);

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'add_project_inventor',
            'subject_type' => 'Project',
            'subject_id'   => $project->id,
            'metadata'     => ['inventor_id' => $inventor->id, 'email' => $inventor->email],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json(['ok' => true, 'inventor' => $inventor->only(['id', 'name', 'email'])], 201);
    }

    public function destroy(Request $request, $projectId, $userId)
    {
        $project = Project::findOrFail($projectId);
        if ($deny = $this->denyUnlessCanManage($request, $project)) {
            return $deny;
        }

        $project->inventors()->detach($userId);

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'remove_project_inventor',
            'subject_type' => 'Project',
            'subject_id'   => $project->id,
            'metadata'     => ['inventor_id' => (int) $userId],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }
}
