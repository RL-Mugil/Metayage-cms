<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Project::class);
    }

    public function rules(): array
    {
        return [
            'client_id'             => 'required|exists:clients,id',
            'project_name'          => 'required|string|max:255',
            'project_type'          => 'required|string|max:100',
            'case_type'             => 'nullable|string|max:100',
            'invention_title'       => 'nullable|string',
            'technology_field'      => 'nullable|string',
            'application_number'    => 'nullable|string|max:100',
            'patent_office_code'    => 'nullable|string|max:10',
            'service_code'          => 'nullable|string|max:50',
            'filing_date'           => 'nullable|date',
            'assigned_partner_id'   => 'nullable|exists:users,id',
            'assigned_manager_id'   => 'nullable|exists:users,id',
            'secondary_manager_id'  => 'nullable|exists:users,id',
            'patent_engineer_id'    => 'nullable|exists:users,id',
            'assigned_team'         => 'nullable|array',
            'start_date'            => 'nullable|date',
            'target_filing_date'    => 'nullable|date',
            'hard_deadline'         => 'nullable|date|after_or_equal:today',
            'fee_arrangement'       => 'nullable|string',
            'urgency'               => 'nullable|string',
            'confidentiality_level' => 'nullable|string',
            'notes'                 => 'nullable|string',
        ];
    }
}
