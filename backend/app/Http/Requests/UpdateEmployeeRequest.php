<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->role, ['super_admin', 'hr']);
    }

    public function rules(): array
    {
        $employeeId = $this->route('id');
        return [
            'employee_code'     => "sometimes|nullable|string|max:30|unique:employees,employee_code,{$employeeId}",
            'full_name'         => 'sometimes|required|string|max:255',
            'work_email'        => 'sometimes|nullable|email|max:255',
            'phone'             => 'nullable|string|max:20',
            'department_id'     => 'nullable|exists:departments,id',
            'department_name'   => 'nullable|string|max:255',
            'designation_id'    => 'nullable|exists:designations,id',
            'designation_title' => 'nullable|string|max:255',
            'employment_type'   => 'nullable|string',
            'employment_status' => 'nullable|string',
            'work_location'     => 'nullable|string',
            'date_of_joining'   => 'nullable|date',
            'salary'            => 'nullable|numeric',
            'reporting_manager_id' => 'nullable|exists:users,id',
        ];
    }
}
