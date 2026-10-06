<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\GovApi\PanVerificationService;
use Illuminate\Http\Request;

/** Triggers PAN verification for one employee — see PanVerificationService. */
class PanVerificationController extends Controller
{
    private const MANAGE_ROLES = User::PAYROLL_ACCESS_ROLES;

    public function __construct(private PanVerificationService $service)
    {
    }

    public function verify(Request $request, $employeeId)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $employee = Employee::findOrFail($employeeId);

        try {
            $result = $this->service->verify($employee);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'verify_employee_pan',
            'subject_type' => 'Employee', 'subject_id' => $employee->id,
            'metadata' => ['result' => $result['status'], 'matches' => $result['matches'] ?? null],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($result);
    }
}
