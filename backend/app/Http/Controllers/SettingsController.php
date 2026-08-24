<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password as PasswordRule;
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
            // Standard deadline-escalation cadence (see ReminderThresholdResolver::
            // escalationCadence() for the defaults these fall back to).
            $data['escalation'] = [
                'at2MonthDays'   => $rows['escalation_2month_days']    ?? '60',
                'at1MonthDays'   => $rows['escalation_1month_days']    ?? '30',
                'noneBeyondDays' => $rows['escalation_none_beyond_days'] ?? '90',
            ];
        }

        return response()->json($data);
    }

    /** Content-sniffed MIME → safe stored extension. Never trust the client-supplied filename. */
    private const AVATAR_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    public function uploadAvatar(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        // Delete old avatar if it's one we stored
        if ($user->avatar_url && str_contains($user->avatar_url, '/storage/avatars/')) {
            $old = str_replace('/storage/', '', parse_url($user->avatar_url, PHP_URL_PATH));
            Storage::disk('public')->delete($old);
        }

        // Extension derived from the content-sniffed MIME type, NOT the client-supplied
        // original filename — getClientOriginalExtension() is attacker-controlled and
        // storing a file under a client-chosen extension (e.g. "shell.php" on an
        // image/PHP polyglot that still passes image validation) would let it execute
        // as PHP once served from the public disk. Fall back to 'jpg' defensively;
        // the `mimes:` rule above already guarantees getMimeType() is one of these four.
        $ext  = self::AVATAR_MIME_EXTENSIONS[$request->file('avatar')->getMimeType()] ?? 'jpg';
        $path = $request->file('avatar')->storeAs('avatars', "user_{$user->id}." . $ext, 'public');
        $url  = Storage::disk('public')->url($path);

        $user->avatar_url = $url;
        $user->save();

        return response()->json(['ok' => true, 'avatar_url' => $url]);
    }

    public function removeAvatar(Request $request)
    {
        $user = $request->user();
        if ($user->avatar_url && str_contains($user->avatar_url, '/storage/avatars/')) {
            $old = str_replace('/storage/', '', parse_url($user->avatar_url, PHP_URL_PATH));
            \Illuminate\Support\Facades\Storage::disk('public')->delete($old);
        }
        $user->avatar_url = null;
        $user->save();
        return response()->json(['ok' => true]);
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
            'password'         => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->symbols()],
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
        Cache::forget('system_settings_shared');

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'update_system_settings',
            'metadata'   => array_keys($validated),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Firm-wide standard escalation cadence — "no escalation beyond 3
     * months, escalate at 2 months, again at 1 month" (see the call notes
     * behind ReminderThresholdResolver::escalationCadence()). A client's own
     * reminder_cadence_override doesn't touch this — it's the fallback used
     * when a client has no override, and drives SendDeadlineRemindersCommand's
     * upcoming-deadline escalation independent of client-specific reminders.
     */
    public function updateEscalationCadence(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['super_admin', 'partner'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'at2MonthDays'   => 'required|integer|min:1|max:3650',
            'at1MonthDays'   => 'required|integer|min:1|max:3650',
            'noneBeyondDays' => 'required|integer|min:1|max:3650',
        ]);

        $map = [
            'at2MonthDays'   => 'escalation_2month_days',
            'at1MonthDays'   => 'escalation_1month_days',
            'noneBeyondDays' => 'escalation_none_beyond_days',
        ];

        foreach ($validated as $field => $value) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $map[$field]],
                ['value' => (string) $value, 'updated_at' => now()]
            );
        }

        Cache::forget('system_settings_shared');

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'update_escalation_cadence',
            'metadata'   => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function updateFeatureFlags(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'feature_link_predecessor'    => 'required|boolean',
            'feature_legacy_case'         => 'required|boolean',
            'feature_existing_client'     => 'required|boolean',
            'feature_lock_code_dropdowns' => 'required|boolean',
        ]);

        foreach ($validated as $key => $value) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value ? 'true' : 'false', 'updated_at' => now()]
            );
        }

        Cache::forget('system_settings_shared');

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'update_feature_flags',
            'metadata'   => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function updateDropdown(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'key'    => 'required|in:dropdown_service_codes,dropdown_country_codes',
            'items'  => 'required|array|min:1',
            'items.*.code'  => 'required|string|max:20',
            'items.*.label' => 'required|string|max:200',
        ]);

        DB::table('system_settings')->updateOrInsert(
            ['key' => $validated['key']],
            ['value' => json_encode($validated['items']), 'updated_at' => now()]
        );

        Cache::forget('system_settings_shared');

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'update_dropdown_' . $validated['key'],
            'metadata'   => ['count' => count($validated['items'])],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Renewal fee rates used by the client portal's "approve renewal" auto-calc
     * (RenewalActionController::approve()). Deliberately a plain lookup, not a
     * fee-schedule engine — the user updates these numbers here from time to
     * time and the portal just multiplies years-selected × rate.
     */
    public function updateRenewalFeeRates(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'government_fee'  => 'required|numeric|min:0',
            'professional_fee' => 'required|numeric|min:0',
            'currency'         => 'nullable|string|max:10',
        ]);

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'renewal_fee_rates'],
            ['value' => json_encode([
                'government_fee'   => (float) $validated['government_fee'],
                'professional_fee' => (float) $validated['professional_fee'],
                'currency'         => $validated['currency'] ?? 'INR',
            ]), 'updated_at' => now()]
        );

        Cache::forget('system_settings_shared');

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'update_renewal_fee_rates',
            'metadata'   => $validated,
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

        $request->validate(['password' => ['required', PasswordRule::min(8)->mixedCase()->symbols()]]);

        $target = User::findOrFail($id);
        $target->password = Hash::make($request->input('password'));
        $target->save();
        $target->tokens()->delete(); // revoke compromised sessions immediately

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
