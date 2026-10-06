<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\GovApi\DigiLockerConsentService;
use Illuminate\Http\Request;

/** DigiLocker consent flow (Aadhaar) — see DigiLockerConsentService. */
class DigiLockerConsentController extends Controller
{
    private const MANAGE_ROLES = User::PAYROLL_ACCESS_ROLES;

    public function __construct(private DigiLockerConsentService $service)
    {
    }

    /**
     * Starts the flow — returns the DigiLocker consent URL to redirect to.
     * Callable by HR/payroll-access on any employee, or by an employee on
     * their own record (self-service, Aadhaar only — DigiLocker consent is
     * personally granted by the Aadhaar holder, so this is the realistic
     * flow; PAN verification stays HR-initiated only, since it's an employer
     * compliance check rather than something an employee self-attests).
     */
    public function start(Request $request, $employeeId)
    {
        $employee = Employee::findOrFail($employeeId);

        return $this->initiate($request, $employee);
    }

    /** Self-service — the caller's own Aadhaar verification status, for the HRMS Payroll self-service card. */
    public function statusMine(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        if (! $employee) {
            return response()->json(['has_employee_record' => false]);
        }

        return response()->json([
            'has_employee_record' => true,
            'has_aadhaar' => ! empty($employee->aadhaar_ssn_encrypted),
            'aadhaar_verification_status' => $employee->aadhaar_verification_status,
            'fetched' => [
                'name' => $employee->aadhaar_verified_name,
                'dob' => $employee->aadhaar_verified_dob?->format('Y-m-d'),
                'address' => $employee->aadhaar_verified_address,
            ],
        ]);
    }

    /** Self-service convenience — resolves the caller's own employee record, no id needed client-side. */
    public function startMine(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        if (! $employee) {
            return response()->json(['message' => 'No employee record linked to your account.'], 403);
        }

        return $this->initiate($request, $employee);
    }

    private function initiate(Request $request, Employee $employee)
    {
        $user = $request->user();
        $isPayrollAccess = in_array($user->role, self::MANAGE_ROLES, true);
        $isOwnRecord = (int) $employee->user_id === (int) $user->id;

        if (! $isPayrollAccess && ! $isOwnRecord) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate(['document_type' => 'required|in:AADHAAR,PAN']);

        if (! $isPayrollAccess && $validated['document_type'] !== 'AADHAAR') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $url = $this->service->buildAuthorizationUrl($employee, $validated['document_type'], $user->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'start_digilocker_consent',
            'subject_type' => 'Employee', 'subject_id' => $employee->id,
            'metadata' => ['document_type' => $validated['document_type']],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['consent_url' => $url]);
    }

    /**
     * DigiLocker redirects the browser here with ?code=&state= after consent.
     * Same browser/session throughout the round trip, so $request->user() is
     * whoever initiated it (HR or the employee themselves) — used to send
     * HR back to the Payroll Portal and a self-service employee back to
     * their own self-service Payroll page.
     */
    public function callback(Request $request)
    {
        $validated = $request->validate(['code' => 'nullable|string', 'state' => 'required|string', 'error' => 'nullable|string']);
        $fallback = $this->redirectTargetFor($request);

        if (! empty($validated['error']) || empty($validated['code'])) {
            return redirect($fallback)->with('error', 'DigiLocker consent was not completed.');
        }

        try {
            $result = $this->service->handleCallback($validated['code'], $validated['state']);
        } catch (\RuntimeException $e) {
            return redirect($fallback)->with('error', $e->getMessage());
        }

        AuditLog::create([
            'user_id' => $request->user()?->id, 'action' => 'complete_digilocker_consent',
            'subject_type' => 'Employee', 'subject_id' => $result['consent']->employee_id,
            'metadata' => ['result' => $result['status'], 'matches' => $result['matches'] ?? null],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        $target = in_array($request->user()?->role, self::MANAGE_ROLES, true)
            ? "/payroll-portal/employees/{$result['consent']->employee_id}"
            : '/hrms/payroll';

        return redirect($target)
            ->with($result['status'] === 'Verified' ? 'success' : 'error', $result['message']);
    }

    private function redirectTargetFor(Request $request): string
    {
        return in_array($request->user()?->role, self::MANAGE_ROLES, true)
            ? '/payroll-portal/employees'
            : '/hrms/payroll';
    }
}
