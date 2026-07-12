<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\RolePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'Active') {
            throw ValidationException::withMessages([
                'email' => ['Your account is suspended.'],
            ]);
        }

        $token = $user->createToken($data['device_name'], ['mobile'])->plainTextToken;

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'mobile_login',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'metadata' => ['device_name' => $data['device_name']],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user && $token) {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'mobile_logout',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'metadata' => ['token_id' => $token->id],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $token->delete();
        }

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'avatar_url' => $user->avatar_url,
            'permissions' => RolePermissions::forRole($user->role),
        ];
    }
}
