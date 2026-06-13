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

        $query = Employee::with('department', 'designation', 'user');

        if ($request->filled('employment_status')) {
            $query->where('employment_status', $request->employment_status);
        }

        if ($request->filled('search')) {
            $s = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->search);
            $query->where(function ($q) use ($s) {
                $q->where('full_name', 'ilike', "%{$s}%")
                  ->orWhere('work_email', 'ilike', "%{$s}%");
            });
        }

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
        $employee = Employee::findOrFail($id);
        $this->authorize('delete', $employee);
        $employee->update(['employment_status' => 'Terminated']);

        return response()->json(['message' => 'Employee deactivated']);
    }

}
