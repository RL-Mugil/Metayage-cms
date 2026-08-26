<?php

namespace App\Http\Requests;

use App\Models\ComplianceItem;
use Illuminate\Foundation\Http\FormRequest;

class UpdateComplianceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = ComplianceItem::find($this->route('id'));
        return $item !== null && ($this->user()?->can('update', $item) ?? false);
    }

    public function rules(): array
    {
        return [
            'assignee_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'assignee' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'resolved' => ['sometimes', 'boolean'],
        ];
    }
}
