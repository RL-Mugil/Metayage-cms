<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Services\PayrollService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Payroll-sensitive fields (salary, bank, statutory, PT exemption) are
        // further restricted to User::PAYROLL_ACCESS_ROLES — stripped from the
        // validated payload in HRMSController::updateEmployee() for anyone
        // else, rather than blocking the whole request. HR keeps editing
        // general employee records; it just can't touch/see payroll data.
        return in_array($this->user()->role, ['super_admin', 'hr', 'partner', 'finance']);
    }

    public function rules(): array
    {
        $employeeId = $this->route('id');
        return [
            'employee_code'     => "sometimes|nullable|string|max:30|unique:employees,employee_code,{$employeeId}",
            'full_name'         => 'sometimes|required|string|max:255',
            'father_name'       => 'nullable|string|max:255',
            'work_email'        => 'sometimes|nullable|email|max:255',
            'phone'             => 'nullable|string|max:20',
            'mobile'            => 'nullable|string|max:20',
            'gender'            => 'nullable|string|max:30',
            'differently_abled_type' => 'nullable|string|max:60',
            'current_address'   => 'nullable|string|max:500',
            'permanent_address' => 'nullable|string|max:500',
            'department_id'     => 'nullable|exists:departments,id',
            'department_name'   => 'nullable|string|max:255',
            'designation_id'    => 'nullable|exists:designations,id',
            'designation_title' => 'nullable|string|max:255',
            'employment_type'   => 'nullable|string',
            'employment_status' => ['nullable', 'string', Rule::in(\App\Models\Employee::STATUSES)],
            'work_location'     => 'nullable|string',
            'date_of_joining'   => 'nullable|date',
            'base_pay_monthly'  => 'nullable|numeric|min:0',
            'hra_monthly'       => 'nullable|numeric|min:0',
            'reporting_manager_id' => 'nullable|exists:users,id',
            // Statutory & bank details — see StoreEmployeeRequest's matching rules.
            'date_of_birth'        => 'nullable|date|before:today',
            'aadhaar_ssn_encrypted' => 'nullable|digits:12',
            'pan_tax_id'            => 'nullable|regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
            'uan_pf_number'         => 'nullable|string|max:30',
            'esi_number'            => 'nullable|string|max:30',
            'nps_number'            => 'nullable|string|max:30',
            'bank_account_number'   => 'nullable|string|max:30',
            'bank_name'             => 'nullable|string|max:120',
            'bank_ifsc_code'        => 'nullable|regex:/^[A-Z]{4}0[A-Z0-9]{6}$/',
            'professional_tax_exempt' => 'nullable|boolean',
            'exclude_from_payroll' => 'nullable|boolean',
        ];
    }

    /**
     * Code on Wages, 2019 §2(y) — Basic must be at least 50% of total
     * remuneration. This is a partial update, so whichever of Base Pay/HRA
     * isn't in this request falls back to the employee's current saved
     * value — the check always validates the resulting FINAL state, not
     * just whatever happened to be submitted.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('base_pay_monthly') && ! $this->has('hra_monthly')) {
                return;
            }

            $employee = Employee::find($this->route('id'));

            $basePay = (float) ($this->input('base_pay_monthly') ?? $employee?->base_pay_monthly ?? 0);
            if ($basePay <= 0) {
                return;
            }

            $hra = (float) ($this->input('hra_monthly') ?? $employee?->hra_monthly ?? 0);
            $percent = app(PayrollService::class)->basicWagePercent($basePay, $hra);

            if ($percent < 50.0) {
                $validator->errors()->add(
                    'hra_monthly',
                    "Basic Pay must be at least 50% of total remuneration under the Code on Wages, 2019 (currently would be {$percent}%). Reduce HRA or increase Base Pay."
                );
            }
        });
    }
}
