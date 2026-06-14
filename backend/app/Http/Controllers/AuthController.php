<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function showLogin()
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Status is checked only after the password is verified so that the
        // response never reveals whether an email exists.
        if (Auth::user()->status !== 'Active') {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => ['Your account is suspended.'],
            ]);
        }

        $request->session()->regenerate();

        AuditLog::create([
            'user_id'    => Auth::id(),
            'action'     => 'login',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata'   => ['email' => $credentials['email']],
        ]);

        return redirect()->intended('/');
    }

    public function logout(Request $request)
    {
        AuditLog::create([
            'user_id'    => Auth::id(),
            'action'     => 'logout',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'role'        => $user->role,
            'status'      => $user->status,
            'avatar_url'  => $user->avatar_url,
            'permissions' => $this->getPermissionsForRole($user->role),
        ]);
    }

    public function users(Request $request)
    {
        $role = $request->user()->role;
        if ($role === 'client') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $users = User::where('status', 'Active')
            ->select('id', 'name', 'email', 'role')
            ->orderBy('name')
            ->get();
        return response()->json($users);
    }

    private function getPermissionsForRole(string $role): array
    {
        $matrix = [
            'super_admin' => ['all' => true],
            'partner'     => [
                'clients'   => 'view_edit',
                'projects'  => 'view_edit',
                'kanban'    => 'view_edit',
                'tasks'     => 'view_edit',
                'documents' => 'view_edit',
                'approvals' => 'approve',
                'financial' => 'view_edit',
                'hrms'      => 'view_edit',
                'reports'   => 'view_edit',
                'ai'        => 'view_edit',
            ],
            'manager' => [
                'clients'   => 'view_edit',
                'projects'  => 'view_edit',
                'kanban'    => 'view_edit',
                'tasks'     => 'view_edit',
                'documents' => 'view_edit',
                'approvals' => 'approve',
                'financial' => 'view',
                'hrms'      => 'department',
                'reports'   => 'view',
                'ai'        => 'view_edit',
            ],
            'associate' => [
                'clients'   => 'view',
                'projects'  => 'view',
                'kanban'    => 'view_edit',
                'tasks'     => 'view_edit',
                'documents' => 'view_edit',
                'approvals' => 'submit',
                'financial' => 'none',
                'hrms'      => 'self_only',
                'reports'   => 'none',
                'ai'        => 'view_edit',
            ],
            'paralegal' => [
                'clients'   => 'view',
                'projects'  => 'view',
                'kanban'    => 'view_edit',
                'tasks'     => 'view_edit',
                'documents' => 'view_edit',
                'approvals' => 'submit',
                'financial' => 'none',
                'hrms'      => 'self_only',
                'reports'   => 'none',
                'ai'        => 'view_edit',
            ],
            'finance' => [
                'clients'   => 'view',
                'projects'  => 'view',
                'financial' => 'view_edit',
                'hrms'      => 'self_only',
                'reports'   => 'view',
            ],
            'hr' => [
                'hrms'      => 'view_edit',
                'clients'   => 'none',
                'projects'  => 'none',
                'financial' => 'none',
            ],
            'client' => [
                'clients'   => 'self_only',
                'projects'  => 'self_only',
                'kanban'    => 'self_only',
                'tasks'     => 'self_only',
                'documents' => 'self_only',
                'financial' => 'self_only',
                'hrms'      => 'none',
            ],
        ];

        return $matrix[$role] ?? [];
    }
}
