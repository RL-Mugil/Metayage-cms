<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->user()->isClientRole();
    }

    public function rules(): array
    {
        return [
            'project_id'  => 'required|exists:projects,id',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'assignee_id' => 'nullable|exists:users,id',
            'reviewer_id' => 'nullable|exists:users,id',
            'priority'    => 'required|string',
            'due_date'    => 'nullable|date',
            'billable'    => 'boolean',
        ];
    }
}
