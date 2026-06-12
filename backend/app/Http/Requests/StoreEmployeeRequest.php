<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->role, ['super_admin', 'hr']);
    }

    public function rules(): array
    {
        return [
            'full_name'         => 'required|string|max:255',
            'work_email'        => 'required|email',
            'phone'             => 'nullable|string|max:20',
            'department_id'     => 'nullable|exists:departments,id',
            'department_name'   => 'nullable|string',
            'designation_id'    => 'nullable|exists:designations,id',
            'designation_title' => 'nullable|string',
            'date_of_joining'   => 'nullable|date',
            'employment_type'   => 'nullable|string',
            'employment_status' => 'nullable|string',
            'work_location'     => 'nullable|string',
            'salary'            => 'nullable|numeric',
            'password'          => 'nullable|string|min:8',
        ];
    }
}
