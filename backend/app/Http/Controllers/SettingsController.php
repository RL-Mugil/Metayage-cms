<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
        ]);

        User::where('id', $user->id)->update($validated);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'update_profile',
            'metadata' => array_keys($validated),
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        User::where('id', $user->id)->update(['password' => Hash::make($validated['password'])]);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'change_password',
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }
}
