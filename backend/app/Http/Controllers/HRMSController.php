<?php

namespace App\Http\Controllers;

use App\Mail\TeamInviteMail;
use App\Models\Employee;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\LeaveBalance;
use App\Models\AuditLog;
use App\Models\User;
use App\Http\PaginationHelper;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;

class HRMSController extends Controller
{
    /** Salary/bank/statutory fields — visible and editable only to User::PAYROLL_ACCESS_ROLES. */
    private const PAYROLL_SENSITIVE_FIELDS = [
        'base_pay_monthly', 'hra_monthly', 'bank_account_number', 'bank_name', 'bank_ifsc_code',
        'aadhaar_ssn_encrypted', 'pan_tax_id', 'uan_pf_number', 'esi_number', 'nps_number',
        'professional_tax_exempt', 'exclude_from_payroll',
    ];

    public function inertiaIndex(Request $request)      { return Inertia::render('HRMS/Index'); }
    public function inertiaEmployees(Request $request)  { return Inertia::render('HRMS/Employees'); }
    public function inertiaAttendance(Request $request) { return Inertia::render('HRMS/Attendance'); }

    /* ──────────────────────────── STATS ──────────────────────────────── */

    public function stats(Request $request)
    {
        $this->authorize('viewAny', \App\Models\Employee::class);

        return response()->json([
            'total'       => Employee::count(),
            'active'      => Employee::where('employment_status', 'Active')->count(),
            'on_leave'    => Employee::where('employment_status', 'On Leave')->count(),
            'departments' => Department::count(),
        ]);
    }

    /* ──────────────────────────── EMPLOYEES ──────────────────────────── */

    public function employees(Request $request)
    {
        $user = $request->user();
        $this->authorize('viewAny', \App\Models\Employee::class);

        $query = Employee::with(['department', 'designation', 'user:id,name,email,role,status,avatar_url']);

        if ($request->filled('employment_status')) {
            $query->where('employment_status', $request->employment_status);
        }

        // Used by HR Overview to show only people currently on the payroll —
        // resigned/terminated staff stay visible on the full Employees list.
        if ($request->boolean('active_only')) {
            $query->whereNotIn('employment_status', Employee::INACTIVE_STATUSES);
        }

        if ($request->filled('search')) {
            $s = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->search);
            $query->where(function ($q) use ($s) {
                $sl = strtolower($s);
                $q->whereRaw('LOWER(full_name) LIKE ?', ["%{$sl}%"])
                  ->orWhereRaw('LOWER(work_email) LIKE ?', ["%{$sl}%"]);
            });
        }

        $result = PaginationHelper::paginate($query, $request);

        $hideSensitive = ! in_array($user->role, User::PAYROLL_ACCESS_ROLES, true);
        $sensitiveFields = self::PAYROLL_SENSITIVE_FIELDS;

        // Today's attendance: pull once, key by employee_id.
        $today = Carbon::now('Asia/Kolkata')->toDateString();
        $todayAtt = Attendance::whereDate('attendance_date', $today)
            ->whereIn('employee_id', $result['data']->pluck('id'))
            ->get()
            ->keyBy('employee_id');

        // Map to plain arrays so extra fields are always serialised.
        $rows = $result['data']->map(function ($emp) use ($todayAtt, $hideSensitive, $sensitiveFields) {
            $arr = $emp->toArray();

            if ($hideSensitive) {
                foreach ($sensitiveFields as $f) { unset($arr[$f]); }
            }

            $att     = $todayAtt->get($emp->id);
            $clockIn = false;
            $status  = 'absent';

            if ($att) {
                $sessions = $att->sessions ?? [];
                $last     = end($sessions) ?: null;
                $clockIn  = $last && isset($last['out']) && $last['out'] === null;
                $status   = $clockIn ? 'clocked_in' : 'clocked_out';
            }

            $arr['clocked_in']   = $clockIn;
            $arr['today_status'] = $status;

            return $arr;
        });

        return response()->json(array_merge($result, ['data' => $rows]));
    }

    public function createEmployee(StoreEmployeeRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        // HR may create employee records but never sets payroll-sensitive
        // fields — silently dropped rather than rejecting the whole request.
        if (! in_array($user->role, User::PAYROLL_ACCESS_ROLES, true)) {
            foreach (self::PAYROLL_SENSITIVE_FIELDS as $field) {
                unset($validated[$field]);
            }
        }

        // Resolve department by name if ID not provided.
        // Values come from a fixed dropdown, so store them exactly as given
        // (title-casing would mangle acronyms like "HR" → "Hr").
        $deptId = $validated['department_id'] ?? null;
        if (!$deptId && !empty($validated['department_name'])) {
            $dept = Department::firstOrCreate(['name' => trim($validated['department_name'])]);
            $deptId = $dept->id;
        }

        // Resolve designation by title if ID not provided.
        $desigId = $validated['designation_id'] ?? null;
        if (!$desigId && !empty($validated['designation_title'])) {
            $desig = Designation::firstOrCreate(['title' => trim($validated['designation_title'])]);
            $desigId = $desig->id;
        }

        $employee = \DB::transaction(function () use ($validated, $deptId, $desigId) {
            // Use custom code if provided; otherwise auto-generate.
            if (!empty($validated['employee_code'])) {
                $code = trim($validated['employee_code']);
            } else {
                $year = date('Y');
                $last = Employee::where('employee_code', 'like', "EMP-{$year}-%")
                    ->orderBy('employee_code', 'desc')
                    ->lockForUpdate()
                    ->value('employee_code');
                $next = $last ? ((int) substr($last, -4)) + 1 : 1;
                $code = sprintf('EMP-%s-%04d', $year, $next);
            }

            // Create linked user account (skip if email already exists).
            // Without an explicit password a random one is set; the user must
            // go through a password reset to gain access.
            $newUser = User::firstOrCreate(
                ['email' => $validated['work_email']],
                [
                    'name'     => $validated['full_name'],
                    'password' => Hash::make($validated['password'] ?? \Illuminate\Support\Str::random(32)),
                    'role'     => 'associate',
                    'status'   => 'Active',
                ]
            );

            $employee = Employee::create([
                'employee_code'    => $code,
                'user_id'          => $newUser->id,
                'full_name'        => $validated['full_name'],
                'father_name'      => $validated['father_name'] ?? null,
                'work_email'       => $validated['work_email'],
                'phone'            => $validated['phone'] ?? null,
                'mobile'           => $validated['mobile'] ?? null,
                'gender'           => $validated['gender'] ?? null,
                'differently_abled_type' => $validated['differently_abled_type'] ?? null,
                'current_address'  => $validated['current_address'] ?? null,
                'permanent_address' => $validated['permanent_address'] ?? null,
                'department_id'    => $deptId,
                'designation_id'   => $desigId,
                'date_of_joining'  => $validated['date_of_joining'] ?? now()->toDateString(),
                'employment_type'  => $validated['employment_type'] ?? 'Full-time',
                'employment_status'=> $validated['employment_status'] ?? 'Active',
                'work_location'    => $validated['work_location'] ?? 'Office',
                'base_pay_monthly' => $validated['base_pay_monthly'] ?? null,
                'hra_monthly'      => $validated['hra_monthly'] ?? null,
                'professional_tax_exempt' => $validated['professional_tax_exempt'] ?? false,
                'exclude_from_payroll' => $validated['exclude_from_payroll'] ?? false,
                'date_of_birth'        => $validated['date_of_birth'] ?? null,
                'aadhaar_ssn_encrypted' => $validated['aadhaar_ssn_encrypted'] ?? null,
                'pan_tax_id'            => $validated['pan_tax_id'] ?? null,
                'uan_pf_number'         => $validated['uan_pf_number'] ?? null,
                'esi_number'            => $validated['esi_number'] ?? null,
                'nps_number'            => $validated['nps_number'] ?? null,
                'bank_account_number'   => $validated['bank_account_number'] ?? null,
                'bank_name'             => $validated['bank_name'] ?? null,
                'bank_ifsc_code'        => $validated['bank_ifsc_code'] ?? null,
            ]);

            LeaveBalance::create([
                'employee_id'  => $employee->id,
                'year'         => date('Y'),
                'earned_leave' => 15,
                'casual_leave' => 8,
                'sick_leave'   => 7,
            ]);

            return $employee;
        });

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'create_employee',
            'subject_type' => 'Employee',
            'subject_id'   => $employee->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json($employee->load(['department', 'designation', 'user:id,name,email,role,status,avatar_url']), 201);
    }

    public function inviteMember(Request $request)
    {
        $actor = $request->user();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
        ]);

        $temporaryPassword = Str::random(14);
        $user = null;

        DB::transaction(function () use ($validated, $temporaryPassword, &$user) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($temporaryPassword),
                'role' => 'associate',
                'status' => 'Active',
            ]);
        });

        try {
            Mail::to($user->email)->send(new TeamInviteMail(
                $user->name,
                $user->email,
                $temporaryPassword,
                url('/login'),
            ));
        } catch (\Throwable $e) {
            $user?->delete();
            throw $e;
        }

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'invite_team_member',
            'subject_type' => 'User',
            'subject_id' => $user->id,
            'metadata' => ['email' => $user->email, 'role' => $user->role],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Workspace invite email sent successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'role' => $user->role,
            ],
        ], 201);
    }

    public function updateEmployee(UpdateEmployeeRequest $request, $id)
    {
        $employee = Employee::findOrFail($id);
        $validated = $request->validated();

        // HR may edit general employee records but never sees or sets
        // payroll-sensitive fields — silently dropped rather than rejecting
        // the whole request, so a mixed-field save from the UI still works.
        if (! in_array($request->user()->role, User::PAYROLL_ACCESS_ROLES, true)) {
            foreach (self::PAYROLL_SENSITIVE_FIELDS as $field) {
                unset($validated[$field]);
            }
        }

        if (!isset($validated['department_id']) && !empty($validated['department_name'])) {
            $dept = Department::firstOrCreate(['name' => $validated['department_name']]);
            $validated['department_id'] = $dept->id;
        }
        if (!isset($validated['designation_id']) && !empty($validated['designation_title'])) {
            $desig = Designation::firstOrCreate(['title' => $validated['designation_title']]);
            $validated['designation_id'] = $desig->id;
        }
        unset($validated['department_name'], $validated['designation_title']);

        // Sync work_email to the linked login account — but never steal an
        // email already used by another account.
        if (!empty($validated['work_email']) && $employee->user && $validated['work_email'] !== $employee->user->email) {
            $taken = User::where('email', $validated['work_email'])->where('id', '!=', $employee->user->id)->exists();
            if ($taken) {
                return response()->json(['message' => 'That email is already used by another account.'], 422);
            }
            $employee->user->update(['email' => $validated['work_email']]);
        }

        $previousStatus = $employee->employment_status;
        $employee->update($validated);

        // Sync user name
        if (isset($validated['full_name']) && $employee->user) {
            $employee->user->update(['name' => $validated['full_name']]);
        }

        // Keep portal login access in step with employment status: resigning
        // or terminating someone locks them out; reinstating them unlocks it.
        if (array_key_exists('employment_status', $validated) && $employee->user_id) {
            $newStatus = $validated['employment_status'];
            $wasInactive = in_array($previousStatus, Employee::INACTIVE_STATUSES, true);
            $nowInactive = in_array($newStatus, Employee::INACTIVE_STATUSES, true);

            if ($nowInactive && !$wasInactive) {
                User::where('id', $employee->user_id)->update(['status' => 'Inactive']);
            } elseif (!$nowInactive && $wasInactive) {
                User::where('id', $employee->user_id)->update(['status' => 'Active']);
            }
        }

        return response()->json($employee->load(['department', 'designation', 'user:id,name,email,role,status,avatar_url']));
    }

    /**
     * Enable/disable this employee's login access to the app (Zoho's "Portal
     * Access" toggle) — maps onto the existing User.status gate already
     * enforced at login (AuthController::login()). Distinct from the
     * Resigned/Terminated → 'Inactive' auto-sync in updateEmployee(): this
     * uses 'Suspended' so a deliberate access-disable survives independent
     * of employment_status changes.
     */
    public function updatePortalAccess(Request $request, $id)
    {
        if (! in_array($request->user()->role, User::PAYROLL_ACCESS_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $employee = Employee::findOrFail($id);
        if (! $employee->user_id) {
            return response()->json(['message' => 'This employee has no linked login account.'], 422);
        }

        $validated = $request->validate(['enabled' => 'required|boolean']);

        $employee->user->update(['status' => $validated['enabled'] ? 'Active' : 'Suspended']);
        if (! $validated['enabled']) {
            $employee->user->tokens()->delete();
        }

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'update_portal_access',
            'subject_type' => 'Employee', 'subject_id' => $employee->id,
            'metadata' => ['enabled' => $validated['enabled']],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['enabled' => $validated['enabled'], 'status' => $employee->user->status]);
    }

    public function deleteEmployee(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);
        $this->authorize('delete', $employee);
        $employee->update(['employment_status' => 'Terminated']);

        // Suspend the linked user account so the ex-employee cannot log in.
        if ($employee->user_id) {
            User::where('id', $employee->user_id)->update(['status' => 'Inactive']);
        }

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'terminate_employee',
            'subject_type' => 'Employee',
            'subject_id'   => $employee->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Employee deactivated']);
    }

    public function employeeWorkload(Request $request)
    {
        $projectCounts = \DB::table('projects')
            ->select('patent_engineer_id as user_id', \DB::raw('COUNT(*) as project_count'))
            ->whereNull('deleted_at')
            ->whereNotNull('patent_engineer_id')
            ->whereNotIn('status', ['Completed', 'Archived'])
            ->groupBy('patent_engineer_id')
            ->get()
            ->keyBy('user_id');

        $trackerCounts = \DB::select("
            SELECT uid AS user_id, COUNT(*) AS tracker_count
            FROM (
                SELECT pcm_id AS uid FROM tracker_rows WHERE pcm_id IS NOT NULL
                UNION ALL
                SELECT scm_id FROM tracker_rows WHERE scm_id IS NOT NULL
                UNION ALL
                SELECT pr_id FROM tracker_rows WHERE pr_id IS NOT NULL
            ) t
            GROUP BY uid
        ");

        $trackerByUser = [];
        foreach ($trackerCounts as $tc) {
            $trackerByUser[(int) $tc->user_id] = (int) $tc->tracker_count;
        }

        $allUserIds = array_unique(array_merge(
            $projectCounts->keys()->toArray(),
            array_keys($trackerByUser)
        ));

        $result = [];
        foreach ($allUserIds as $uid) {
            $uid = (int) $uid;
            $pCount = isset($projectCounts[$uid]) ? (int) $projectCounts[$uid]->project_count : 0;
            $tCount = $trackerByUser[$uid] ?? 0;
            $result[] = ['user_id' => $uid, 'project_count' => $pCount, 'tracker_count' => $tCount, 'total' => $pCount + $tCount];
        }

        return response()->json($result);
    }

}
