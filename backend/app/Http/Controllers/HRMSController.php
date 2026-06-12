<?php

namespace App\Http\Controllers;

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
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class HRMSController extends Controller
{
    public function inertiaIndex(Request $request)      { return Inertia::render('HRMS/Index'); }
    public function inertiaEmployees(Request $request)  { return Inertia::render('HRMS/Employees'); }
    public function inertiaAttendance(Request $request) { return Inertia::render('HRMS/Attendance'); }

    /* ──────────────────────────── EMPLOYEES ──────────────────────────── */

    public function employees(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'partner', 'manager', 'hr'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = Employee::with('department', 'designation', 'user');
        $result = PaginationHelper::paginate($query, $request);

        // Compensation, banking and identity fields are HR-only.
        if (! in_array($user->role, ['super_admin', 'hr'])) {
            $result['data']->makeHidden([
                'salary', 'bank_account_number', 'bank_name', 'bank_ifsc_code',
                'aadhaar_ssn_encrypted', 'pan_tax_id', 'uan_pf_number', 'esi_number',
            ]);
        }

        return response()->json($result);
    }

    public function createEmployee(StoreEmployeeRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        // Resolve department by name if ID not provided
        $deptId = $validated['department_id'] ?? null;
        if (!$deptId && !empty($validated['department_name'])) {
            $dept = Department::firstOrCreate(['name' => $validated['department_name']]);
            $deptId = $dept->id;
        }

        // Resolve designation by title if ID not provided
        $desigId = $validated['designation_id'] ?? null;
        if (!$desigId && !empty($validated['designation_title'])) {
            $desig = Designation::firstOrCreate(['title' => $validated['designation_title']]);
            $desigId = $desig->id;
        }

        $employee = \DB::transaction(function () use ($validated, $deptId, $desigId) {
            // Auto-generate employee code from the highest existing code for
            // the year (row-locked to avoid duplicate codes under concurrency).
            $year = date('Y');
            $last = Employee::where('employee_code', 'like', "EMP-{$year}-%")
                ->orderBy('employee_code', 'desc')
                ->lockForUpdate()
                ->value('employee_code');
            $next = $last ? ((int) substr($last, -4)) + 1 : 1;
            $code = sprintf('EMP-%s-%04d', $year, $next);

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

        return response()->json($employee->load('department', 'designation', 'user'), 201);
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

        $employee->update($validated);

        // Sync user name
        if (isset($validated['full_name']) && $employee->user) {
            $employee->user->update(['name' => $validated['full_name']]);
        }

        return response()->json($employee->load('department', 'designation', 'user'));
    }

    public function deleteEmployee(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, ['super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $employee = Employee::findOrFail($id);
        $employee->update(['employment_status' => 'Terminated']);

        return response()->json(['message' => 'Employee deactivated']);
    }

    /* ──────────────────────────── ATTENDANCE ──────────────────────────── */

    public function attendance(Request $request)
    {
        $user     = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();
        if (! $employee) return response()->json([]);

        $logs = Attendance::where('employee_id', $employee->id)
            ->orderBy('attendance_date', 'desc')
            ->take(30)
            ->get();

        return response()->json($logs);
    }

    public function clockIn(Request $request)
    {
        $user     = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();

        if (! $employee) {
            return response()->json(['message' => 'No employee profile linked to your account. Contact HR.'], 422);
        }

        $today    = Carbon::today()->toDateString();
        $existing = Attendance::where('employee_id', $employee->id)->whereDate('attendance_date', $today)->first();

        if ($existing) {
            return response()->json(['message' => 'Already checked in today'], 400);
        }

        $log = Attendance::create([
            'employee_id'    => $employee->id,
            'attendance_date'=> $today,
            'check_in'       => Carbon::now()->toTimeString(),
            'capture_method' => 'Web Check-in',
            'location_gps'   => $request->location_gps,
            'status'         => 'Present',
        ]);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'clock_in',
            'subject_type' => 'Attendance', 'subject_id' => $log->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($log, 201);
    }

    public function clockOut(Request $request)
    {
        $user     = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();

        if (! $employee) {
            return response()->json(['message' => 'No employee profile linked to your account.'], 422);
        }

        $today = Carbon::today()->toDateString();
        $log   = Attendance::where('employee_id', $employee->id)->whereDate('attendance_date', $today)->firstOrFail();

        $checkoutTime    = Carbon::now()->toTimeString();
        $durationMinutes = (int) abs(Carbon::parse($log->check_in)->diffInMinutes(Carbon::parse($checkoutTime)));

        $log->update(['check_out' => $checkoutTime, 'duration_minutes' => $durationMinutes]);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'clock_out',
            'subject_type' => 'Attendance', 'subject_id' => $log->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($log);
    }

    /* ──────────────────────────── LEAVES ──────────────────────────── */

    public function leaves(Request $request)
    {
        $user = $request->user();
        if ($user->role === 'client') return response()->json(['message' => 'Forbidden'], 403);

        $employee   = Employee::where('user_id', $user->id)->first();
        $isApprover = in_array($user->role, ['super_admin', 'hr', 'manager', 'partner']);

        // Approvers see the whole firm's requests; everyone else only their own.
        $query = LeaveRequest::with('employee:id,full_name,user_id')->orderBy('from_date', 'desc');
        if (! $isApprover) {
            if (! $employee) return response()->json(['requests' => [], 'balances' => null, 'is_approver' => false]);
            $query->where('employee_id', $employee->id);
        }

        $requests = $query->get()->map(fn ($r) => [
            'id'            => $r->id,
            'employee_id'   => $r->employee_id,
            'employee_name' => $r->employee?->full_name,
            'leave_type'    => $r->leave_type,
            'from_date'     => $r->from_date,
            'to_date'       => $r->to_date,
            'total_days'    => $r->total_days,
            'reason'        => $r->reason,
            'status'        => $r->status,
            'comments'      => $r->comments,
            'is_mine'       => $employee ? $r->employee_id === $employee->id : false,
        ]);

        $balances = $employee
            ? LeaveBalance::where('employee_id', $employee->id)->where('year', date('Y'))->first()
            : null;

        return response()->json([
            'requests'    => $requests,
            'balances'    => $balances,
            'is_approver' => $isApprover,
        ]);
    }

    public function applyLeave(Request $request)
    {
        $user     = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();

        if (! $employee) return response()->json(['message' => 'No employee profile linked to your account.'], 422);

        $request->validate([
            'leave_type' => 'required|string',
            'from_date'  => 'required|date',
            'to_date'    => 'required|date|after_or_equal:from_date',
            'reason'     => 'required|string',
        ]);

        $totalDays = Carbon::parse($request->to_date)->diffInDays(Carbon::parse($request->from_date)) + 1;

        $leaveReq = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type'  => $request->leave_type,
            'from_date'   => $request->from_date,
            'to_date'     => $request->to_date,
            'total_days'  => $totalDays,
            'reason'      => $request->reason,
            'status'      => 'Pending',
        ]);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'apply_leave',
            'subject_type' => 'LeaveRequest', 'subject_id' => $leaveReq->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($leaveReq, 201);
    }

    public function updateLeave(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'hr', 'manager', 'partner'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $leave = LeaveRequest::findOrFail($id);
        $request->validate(['status' => 'required|in:Approved,Rejected,Cancelled']);

        $leave = app(\App\Services\LeaveApprovalService::class)
            ->resolve($leave, $request->status, $user->id, $request->comments);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'resolve_leave',
            'subject_type' => 'LeaveRequest', 'subject_id' => $leave->id,
            'metadata' => ['status' => $request->status],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($leave);
    }
}
