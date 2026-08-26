<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreComplianceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\ComplianceItem::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'matter' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['Patent', 'Trademark', 'Copyright', 'Design', 'Other'])],
            'jurisdiction' => ['required', 'string', 'max:60'],
            'deadline' => ['required', 'date'],
            'action_required' => ['required', 'string', 'max:500'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
