<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function getSettings(Request $request)
    {
        $user = $request->user()->fresh();

        $data = [
            'profile' => [
                'name'     => $user->name,
                'email'    => $user->email,
                'timezone' => $user->timezone ?? 'Asia/Kolkata',
                'language' => $user->language ?? 'English',
            ],
            'notifications' => $user->notification_prefs ?? [
                'taskAssigned'    => true,
                'deadlineEmail'   => true,
                'paymentReceived' => true,
                'pushNotif'       => false,
                'weeklyDigest'    => true,
                'monthlyReport'   => true,
            ],
            'system' => null,
        ];

        if (in_array($user->role, ['super_admin', 'partner'])) {
            $rows = DB::table('system_settings')->pluck('value', 'key');
            $data['system'] = [
                'company'     => $rows['company_name']  ?? 'My IP Strategy',
                'currency'    => $rows['currency']      ?? 'INR',
                'fiscalMonth' => $rows['fiscal_month']  ?? 'April',
                'maxUploadMB' => $rows['max_upload_mb'] ?? '50',
            ];
        }

        return response()->json($data);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email,' . $user->id,
            'timezone' => 'nullable|string|max:64',
            'language' => 'nullable|string|max:50',
        ]);

        $user->name     = $validated['name'];
        $user->email    = $validated['email'];
        if (isset($validated['timezone'])) $user->timezone = $validated['timezone'];
        if (isset($validated['language'])) $user->language = $validated['language'];
        $user->save();

        // Keep linked Employee record in sync so HRMS displays current name/email.
        $employee = Employee::where('user_id', $user->id)->first();
        if ($employee) {
            $employee->update([
                'full_name'  => $validated['name'],
                'work_email' => $validated['email'],
            ]);
        }

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'update_profile',
            'metadata'   => array_keys($validated),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
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

        $user->password = $validated['password']; // cast handles hashing
        $user->save();
        $user->tokens()->delete(); // revoke all active API tokens so compromised sessions die

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'change_password',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function updateNotifications(Request $request)
    {
        $user = $request->user();
        $prefs = $request->validate([
            'taskAssigned'    => 'boolean',
            'deadlineEmail'   => 'boolean',
            'paymentReceived' => 'boolean',
            'pushNotif'       => 'boolean',
            'weeklyDigest'    => 'boolean',
            'monthlyReport'   => 'boolean',
        ]);

        $user->notification_prefs = $prefs;
        $user->save();

        return response()->json(['ok' => true]);
    }

    public function updateSystem(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['super_admin', 'partner'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'company'     => 'required|string|max:255',
            'currency'    => 'required|string|max:10',
            'fiscalMonth' => 'required|string|max:20',
            'maxUploadMB' => 'required|integer|min:1|max:500',
        ]);

        $map = [
            'company'     => 'company_name',
            'currency'    => 'currency',
            'fiscalMonth' => 'fiscal_month',
            'maxUploadMB' => 'max_upload_mb',
        ];

        foreach ($validated as $field => $value) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $map[$field]],
                ['value' => (string) $value, 'updated_at' => now()]
            );
        }

        Cache::forget('system_settings');

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'update_system_settings',
            'metadata'   => array_keys($validated),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function resetUserPassword(Request $request, $id)
    {
        $actor = $request->user();
        if (! in_array($actor->role, ['super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate(['password' => 'required|string|min:6']);

        $target = User::findOrFail($id);
        $target->password = $request->input('password');
        $target->save();

        AuditLog::create([
            'user_id'      => $actor->id,
            'action'       => 'admin_reset_password',
            'subject_type' => 'User',
            'subject_id'   => $target->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }
}
