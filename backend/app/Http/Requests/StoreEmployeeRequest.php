<?php

namespace App\Http\Requests;

use App\Services\PayrollService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Employee::class);
    }

    public function rules(): array
    {
        return [
            'employee_code'     => 'nullable|string|max:30|unique:employees,employee_code',
            'full_name'         => 'required|string|max:255',
            'father_name'       => 'nullable|string|max:255',
            'work_email'        => 'required|email',
            'phone'             => 'nullable|string|max:20',
            'mobile'            => 'nullable|string|max:20',
            'gender'            => 'nullable|string|max:30',
            'differently_abled_type' => 'nullable|string|max:60',
            'current_address'   => 'nullable|string|max:500',
            'permanent_address' => 'nullable|string|max:500',
            'department_id'     => 'nullable|exists:departments,id',
            'department_name'   => 'nullable|string',
            'designation_id'    => 'nullable|exists:designations,id',
            'designation_title' => 'nullable|string',
            'date_of_joining'   => 'nullable|date',
            'employment_type'   => 'nullable|string',
            'employment_status' => ['nullable', 'string', Rule::in(\App\Models\Employee::STATUSES)],
            'work_location'     => 'nullable|string',
            'base_pay_monthly'  => 'nullable|numeric|min:0',
            'hra_monthly'       => 'nullable|numeric|min:0',
            'password'          => 'nullable|string|min:8',
            // Statutory & bank details — columns already existed on `employees`
            // but were never whitelisted here, so the UI/Form Request never
            // actually persisted them. See Employees.tsx "Statutory & Bank
            // Details" section.
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
     * remuneration. Only checked when a Base Pay is actually being set (an
     * employee with no salary data yet — e.g. a non-payroll admin account —
     * isn't subject to this), consistent with PayrollService::basicWagePercent().
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $basePay = (float) ($this->input('base_pay_monthly') ?? 0);
            if ($basePay <= 0) {
                return;
            }

            $hra = (float) ($this->input('hra_monthly') ?? 0);
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
