<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\RolePermissions;
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

        if ($request->user() && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

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
        if ($request->user()->isClientRole()) {
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
        return RolePermissions::forRole($role);
    }
}
