<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Staff (non-client) user administration. System admin only.
 */
class StaffUserController extends Controller
{
    private const STAFF_ROLES = User::STAFF_ROLES;

    private function denyNonAdmin(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    public function index(Request $request)
    {
        if ($deny = $this->denyNonAdmin($request)) return $deny;

        $query = User::whereNotIn('role', User::CLIENT_ROLES)
            ->orderBy('name');

        if ($request->filled('search')) {
            $s = strtolower($request->search);
            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(name) LIKE ?', ["%{$s}%"])
                ->orWhereRaw('LOWER(email) LIKE ?', ["%{$s}%"]));
        }

        return response()->json(
            $query->get(['id', 'name', 'email', 'role', 'status', 'created_at'])
        );
    }

    public function store(Request $request)
    {
        if ($deny = $this->denyNonAdmin($request)) return $deny;

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'role'     => ['required', Rule::in(self::STAFF_ROLES)],
            'password' => 'required|string|min:6|max:100',
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'role'     => $validated['role'],
            'password' => Hash::make($validated['password']),
            'status'   => 'Active',
        ]);

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'create_staff_user',
            'subject_type' => 'User',
            'subject_id'   => $user->id,
            'metadata'     => ['email' => $user->email, 'role' => $user->role],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json($user->only(['id', 'name', 'email', 'role', 'status', 'created_at']), 201);
    }

    public function update(Request $request, $id)
    {
        if ($deny = $this->denyNonAdmin($request)) return $deny;

        $user = User::findOrFail($id);
        if ($user->isClientRole()) {
            return response()->json(['message' => 'Portal users are managed from the Client Portal tab.'], 422);
        }

        $validated = $request->validate([
            'name'   => 'sometimes|required|string|max:255',
            'email'  => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role'   => ['sometimes', 'required', Rule::in(self::STAFF_ROLES)],
            'status' => 'sometimes|required|in:Active,Suspended,Inactive',
        ]);

        // Don't let the last admin demote/suspend themselves.
        if ($user->id === $request->user()->id
            && (($validated['role'] ?? $user->role) !== 'super_admin' || ($validated['status'] ?? $user->status) !== 'Active')) {
            return response()->json(['message' => 'You cannot demote or suspend your own admin account.'], 422);
        }

        $user->update($validated);

        // Suspension revokes access immediately.
        if (($validated['status'] ?? null) && $validated['status'] !== 'Active') {
            $user->tokens()->delete();
        }

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'update_staff_user',
            'subject_type' => 'User',
            'subject_id'   => $user->id,
            'metadata'     => $validated,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json($user->only(['id', 'name', 'email', 'role', 'status', 'created_at']));
    }

    public function destroy(Request $request, $id)
    {
        if ($deny = $this->denyNonAdmin($request)) return $deny;

        $user = User::findOrFail($id);
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }
        if ($user->isClientRole()) {
            return response()->json(['message' => 'Portal users are managed from the Client Portal tab.'], 422);
        }

        try {
            $user->tokens()->delete();
            $user->delete();
        } catch (\Illuminate\Database\QueryException) {
            return response()->json([
                'message' => 'This user is linked to other records (e.g. an employee profile or assigned cases). Suspend them instead.',
            ], 422);
        }

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'delete_staff_user',
            'subject_type' => 'User',
            'subject_id'   => (int) $id,
            'metadata'     => ['email' => $user->email],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }
}
