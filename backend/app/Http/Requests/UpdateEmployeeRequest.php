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
        return [
            'full_name'         => 'sometimes|required|string|max:255',
            'phone'             => 'nullable|string|max:20',
            'department_id'     => 'nullable|exists:departments,id',
            'designation_id'    => 'nullable|exists:designations,id',
            'employment_type'   => 'nullable|string',
            'employment_status' => 'nullable|string',
            'work_location'     => 'nullable|string',
            'salary'            => 'nullable|numeric',
            'reporting_manager_id' => 'nullable|exists:users,id',
        ];
    }
}
