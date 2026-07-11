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

        if ($request->filled('search')) {
            $s = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->search);
            $query->where(function ($q) use ($s) {
                $sl = strtolower($s);
                $q->whereRaw('LOWER(full_name) LIKE ?', ["%{$sl}%"])
                  ->orWhereRaw('LOWER(work_email) LIKE ?', ["%{$sl}%"]);
            });
        }

        $result = PaginationHelper::paginate($query, $request);

        $hideSensitive = ! in_array($user->role, ['super_admin', 'hr']);
        $sensitiveFields = ['salary', 'bank_account_number', 'bank_name', 'bank_ifsc_code',
                            'aadhaar_ssn_encrypted', 'pan_tax_id', 'uan_pf_number', 'esi_number'];

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
                'work_email'       => $validated['work_email'],
                'phone'            => $validated['phone'] ?? null,
                'department_id'    => $deptId,
                'designation_id'   => $desigId,
                'date_of_joining'  => $validated['date_of_joining'] ?? now()->toDateString(),
                'employment_type'  => $validated['employment_type'] ?? 'Full-time',
                'employment_status'=> $validated['employment_status'] ?? 'Active',
                'work_location'    => $validated['work_location'] ?? 'Office',
                'salary'           => $validated['salary'] ?? null,
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

        $employee->update($validated);

        // Sync user name
        if (isset($validated['full_name']) && $employee->user) {
            $employee->user->update(['name' => $validated['full_name']]);
        }

        return response()->json($employee->load(['department', 'designation', 'user:id,name,email,role,status,avatar_url']));
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
