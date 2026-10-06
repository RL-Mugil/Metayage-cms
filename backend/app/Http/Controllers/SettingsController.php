<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\CodeRenameService;
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

    public function resetInternalSignaturePin(Request $request)
    {
        $validated = $request->validate(['pin' => ['required', 'digits:4']]);
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();
        $employee->setInternalSignaturePin($validated['pin']);
        $employee->save();

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'reset_internal_signature_pin',
            'subject_type' => 'Employee',
            'subject_id' => $employee->id,
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

    public function updateDropdown(Request $request, CodeRenameService $codeRenames)
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

        // Block dropping a code that's still assigned to any project — matches the "no
        // orphaned mapping" pattern already enforced by updateProjectTabs(). This also means
        // trying to silently rename a code by editing it in this whole-array-replace endpoint
        // (rather than the dedicated cascading rename below) gets caught here once the old
        // code is actually in use, nudging toward the cascade-aware flow instead.
        $type = $validated['key'] === 'dropdown_service_codes' ? 'service' : 'country';
        $existingRaw = DB::table('system_settings')->where('key', $validated['key'])->value('value');
        $existingCodes = $existingRaw
            ? array_map(fn ($i) => strtoupper($i['code']), json_decode($existingRaw, true) ?: [])
            : [];
        $newCodes = array_map(fn ($i) => strtoupper($i['code']), $validated['items']);
        foreach (array_diff($existingCodes, $newCodes) as $removedCode) {
            $count = $codeRenames->usageCount($type, $removedCode);
            if ($count > 0) {
                throw ValidationException::withMessages([
                    'items' => "'{$removedCode}' is still used by {$count} project(s) — cannot delete. Rename it instead, or reassign those projects first.",
                ]);
            }
        }

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
     * Admin-configurable mapping of Projects-list tabs to service codes (e.g. "Examination"
     * -> FER/SER/TER). Stored as one system_settings row (key 'project_tabs') holding the
     * whole ordered array, mirroring updateDropdown()'s whole-array-replace pattern.
     *
     * The 'others' tab is a system-derived catch-all (anything not explicitly assigned to
     * another tab) and its invariants are enforced here, never trusted from the client:
     * exactly one must exist, it must carry no explicit codes, and it can't be removed.
     */
    public function updateProjectTabs(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'tabs'             => 'required|array|min:1',
            'tabs.*.key'       => 'required|string|max:50|regex:/^[a-z0-9_]+$/',
            'tabs.*.label'     => 'required|string|max:100',
            'tabs.*.codes'     => 'array',
            'tabs.*.codes.*'   => 'string|max:10',
            'tabs.*.is_system' => 'boolean',
        ]);

        $tabs = $validated['tabs'];

        $keys = array_column($tabs, 'key');
        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages([
                'tabs' => 'Tab keys must be unique.',
            ]);
        }

        $othersTabs = array_filter($tabs, fn ($t) => $t['key'] === 'others');
        if (count($othersTabs) !== 1) {
            throw ValidationException::withMessages([
                'tabs' => 'The Others tab cannot be removed.',
            ]);
        }
        $others = array_values($othersTabs)[0];
        if (!empty($others['codes'])) {
            throw ValidationException::withMessages([
                'tabs.others.codes' => 'Codes cannot be assigned directly to the Others tab.',
            ]);
        }

        // No service code may belong to more than one explicit (non-Others) tab.
        $codeOwner = [];
        foreach ($tabs as $tab) {
            if ($tab['key'] === 'others') {
                continue;
            }
            foreach (($tab['codes'] ?? []) as $code) {
                $code = strtoupper($code);
                if (isset($codeOwner[$code])) {
                    throw ValidationException::withMessages([
                        'tabs' => "Service code '{$code}' is already assigned to tab '{$codeOwner[$code]}' — remove it there first.",
                    ]);
                }
                $codeOwner[$code] = $tab['label'];
            }
        }

        // Normalize: force is_system/empty-codes on the Others tab server-side, uppercase codes.
        $normalized = array_map(function ($tab) {
            $isOthers = $tab['key'] === 'others';
            return [
                'key'       => $tab['key'],
                'label'     => $tab['label'],
                'codes'     => $isOthers ? [] : array_values(array_unique(array_map('strtoupper', $tab['codes'] ?? []))),
                'is_system' => $isOthers,
            ];
        }, $tabs);

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'project_tabs'],
            ['value' => json_encode($normalized), 'updated_at' => now()]
        );

        Cache::forget('system_settings_shared');

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'update_project_tabs',
            'metadata'   => [
                'tab_count' => count($normalized),
                'tab_keys'  => array_column($normalized, 'key'),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true, 'tabs' => $normalized]);
    }

    /**
     * Service Codes / Country Codes list for the Settings → Codes tab, each entry annotated
     * with how many projects (any status, incl. soft-deleted) currently carry it — drives the
     * "# Projects" column and the disabled/tooltip state on the delete action client-side.
     * Read-only, so partner (who can already see System/Finance) is allowed too.
     */
    public function codesIndex(Request $request, CodeRenameService $codeRenames)
    {
        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'partner'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'service' => $codeRenames->dropdownWithCounts('service'),
            'country' => $codeRenames->dropdownWithCounts('country'),
        ]);
    }

    /**
     * Read-only preview of what a code rename would touch (counts, incl. per-table
     * collision-skip counts) — powers the confirm dialog. No writes.
     */
    public function previewCodeRename(Request $request, CodeRenameService $codeRenames)
    {
        $user = $request->user();
        if ($user->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'type'     => 'required|in:service,country',
            'old_code' => 'required|string|max:20',
            'new_code' => 'required|string|max:20',
        ]);

        $old = strtoupper(trim($validated['old_code']));
        $new = strtoupper(trim($validated['new_code']));

        // If the target code already exists as a separate, distinct catalog entry, offer a
        // merge instead of dead-ending here — a normal 200 with a flag, not a validation error,
        // so the frontend can branch into the merge preview/confirm flow.
        if ($old !== $new) {
            $existing = $codeRenames->findExistingCode($validated['type'], $new);
            if ($existing) {
                return response()->json([
                    'merge_available' => true,
                    'old_code' => $old,
                    'new_code' => $new,
                    'existing_label' => $existing['label'],
                    'existing_count' => $existing['projects_count'],
                ]);
            }
        }

        return response()->json(
            $codeRenames->previewImpact($validated['type'], $old, $new)
        );
    }

    /**
     * Read-only preview of what merging $old into an already-existing $new would touch — powers
     * the merge-confirm dialog once previewCodeRename() has signaled merge_available.
     */
    public function previewCodeMerge(Request $request, CodeRenameService $codeRenames)
    {
        $user = $request->user();
        if ($user->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'type'     => 'required|in:service,country',
            'old_code' => 'required|string|max:20',
            'new_code' => 'required|string|max:20',
        ]);

        return response()->json(
            $codeRenames->previewMergeImpact($validated['type'], $validated['old_code'], $validated['new_code'])
        );
    }

    /**
     * Executes a code merge — folds $old into an already-existing $new and cascades that into
     * every live table that keys off the code. super_admin only, same reasoning as
     * executeCodeRename(): a cascading write, materially more dangerous than a whole-array
     * dropdown replace (and, unlike a plain rename, also permanently discards any config rows
     * that collide with the target's existing ones — see CodeRenameService).
     */
    public function executeCodeMerge(Request $request, CodeRenameService $codeRenames)
    {
        $user = $request->user();
        if ($user->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'type'     => 'required|in:service,country',
            'old_code' => 'required|string|max:20',
            'new_code' => 'required|string|max:20',
        ]);

        $result = $codeRenames->executeMerge(
            $validated['type'],
            $validated['old_code'],
            $validated['new_code'],
            $user->id,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json(['ok' => true] + $result);
    }

    /**
     * Executes a code rename and cascades it into every live table that keys off the code —
     * see CodeRenameService for exactly what is and isn't touched. super_admin only: this is a
     * cascading write, materially more dangerous than a whole-array dropdown replace.
     */
    public function executeCodeRename(Request $request, CodeRenameService $codeRenames)
    {
        $user = $request->user();
        if ($user->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'type'     => 'required|in:service,country',
            'old_code' => 'required|string|max:20',
            'new_code' => 'required|string|max:20',
        ]);

        $result = $codeRenames->execute(
            $validated['type'],
            $validated['old_code'],
            $validated['new_code'],
            $user->id,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json(['ok' => true] + $result);
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

    /**
     * The Karnataka Professional Tax slab PayrollService applies to every
     * payslip (system_settings keys `payroll_pt_nil_threshold`/
     * `payroll_pt_monthly_amount`/`payroll_pt_february_amount` — the firm's
     * legal entity/TAN/ROC registration is Karnataka/Bangalore, so this one
     * slab applies firm-wide regardless of where an employee physically
     * works). Read directly from the DB by PayrollService — never through
     * this request's cached shared-prop view, since payroll generation runs
     * in a queue worker.
     */
    public function updatePayrollSettings(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, User::PAYROLL_ACCESS_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'pt_nil_threshold' => 'required|numeric|min:0',
            'pt_monthly_amount' => 'required|numeric|min:0',
            'pt_february_amount' => 'required|numeric|min:0',
            'pf_enabled' => 'nullable|boolean',
            'esi_enabled' => 'nullable|boolean',
            'company_name' => 'nullable|string|max:255',
            'company_address' => 'nullable|string|max:500',
            'company_logo_url' => 'nullable|string|max:2048',
            'company_tan' => 'nullable|string|max:20',
            'pay_day' => 'nullable|integer|min:1|max:31',
            'signatory_name' => 'nullable|string|max:255',
            'signatory_designation' => 'nullable|string|max:255',
        ]);

        $now = now();
        $rows = [
            ['key' => 'payroll_pt_nil_threshold', 'value' => (string) round((float) $validated['pt_nil_threshold'], 2)],
            ['key' => 'payroll_pt_monthly_amount', 'value' => (string) round((float) $validated['pt_monthly_amount'], 2)],
            ['key' => 'payroll_pt_february_amount', 'value' => (string) round((float) $validated['pt_february_amount'], 2)],
        ];
        if (array_key_exists('pf_enabled', $validated)) {
            $rows[] = ['key' => 'payroll_pf_enabled', 'value' => $validated['pf_enabled'] ? 'true' : 'false'];
        }
        if (array_key_exists('esi_enabled', $validated)) {
            $rows[] = ['key' => 'payroll_esi_enabled', 'value' => $validated['esi_enabled'] ? 'true' : 'false'];
        }
        if (array_key_exists('company_name', $validated)) {
            $rows[] = ['key' => 'payroll_company_name', 'value' => (string) $validated['company_name']];
        }
        if (array_key_exists('company_address', $validated)) {
            $rows[] = ['key' => 'payroll_company_address', 'value' => (string) $validated['company_address']];
        }
        if (array_key_exists('company_logo_url', $validated)) {
            $rows[] = ['key' => 'payroll_company_logo_url', 'value' => (string) $validated['company_logo_url']];
        }
        if (array_key_exists('company_tan', $validated)) {
            $rows[] = ['key' => 'payroll_company_tan', 'value' => (string) $validated['company_tan']];
        }
        if (array_key_exists('pay_day', $validated)) {
            $rows[] = ['key' => 'payroll_pay_day', 'value' => (string) (int) $validated['pay_day']];
        }
        if (array_key_exists('signatory_name', $validated)) {
            $rows[] = ['key' => 'payroll_signatory_name', 'value' => (string) $validated['signatory_name']];
        }
        if (array_key_exists('signatory_designation', $validated)) {
            $rows[] = ['key' => 'payroll_signatory_designation', 'value' => (string) $validated['signatory_designation']];
        }

        foreach ($rows as $row) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $row['key']],
                ['value' => $row['value'], 'updated_at' => $now]
            );
        }

        Cache::forget('system_settings_shared');

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'update_payroll_settings',
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
