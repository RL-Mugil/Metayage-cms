<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFamilyBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = \App\Models\Project::find($this->input('source_project_id'));
        return $project !== null && $this->user()->can('update', $project);
    }

    public function rules(): array
    {
        return [
            'source_project_id' => 'required|integer|exists:projects,id',
            'patent_office_code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{2}$/'],
            'service_code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{3}$/'],
            'application_number' => 'nullable|string|max:100',
            'filing_date' => 'nullable|date',
            'hard_deadline' => 'nullable|date',
            'docket_reviewer_id' => 'nullable|integer|exists:users,id',
            'note' => 'nullable|string|max:1000',
            'complete_source' => 'sometimes|boolean',
        ];
    }
}
