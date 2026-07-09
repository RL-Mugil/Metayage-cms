<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (in_array($user->role, ['super_admin', 'partner'])) return true;
        // Managers can update projects they manage
        if ($user->role === 'manager') {
            $project = \App\Models\Project::find($this->route('id'));
            return $project && $project->assigned_manager_id === $user->id;
        }
        return false;
    }

    public function rules(): array
    {
        return [
            'project_name'          => 'sometimes|required|string|max:255',
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
            'hard_deadline'         => 'nullable|date',
            'status'                => 'nullable|string|max:50',
            'idf_received_date'     => 'nullable|date',
            'advance_payment_date'  => 'nullable|date',
            'partial_payment_date'  => 'nullable|date',
            'full_payment_date'     => 'nullable|date',
            'urgency'               => 'nullable|string',
            'notes'                 => 'nullable|string',
        ];
    }
}
