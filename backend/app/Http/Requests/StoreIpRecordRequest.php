<?php

namespace App\Http\Requests;

use App\Models\IpRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIpRecordRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('create', IpRecord::class) === true; }
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'record_type' => ['required', Rule::in(['Patent', 'Trademark'])],
            'jurisdiction' => ['required', 'string', 'size:2'],
            'title' => ['required', 'string', 'max:500'],
            'client_reference' => ['nullable', 'string', 'max:100'],
            'legal_status' => ['nullable', 'string', 'max:64'],
            'status_date' => ['nullable', 'date'],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'backup_user_id' => ['nullable', 'integer', 'different:responsible_user_id', 'exists:users,id'],
            'tags' => ['nullable', 'array'], 'tags.*' => ['string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'patent' => ['nullable', 'array'],
            'patent.application_number' => ['nullable', 'string', 'max:100'],
            'patent.priority_date' => ['nullable', 'date'], 'patent.filing_date' => ['nullable', 'date'],
            'trademark' => ['nullable', 'array'],
            'trademark.application_number' => ['nullable', 'string', 'max:100'],
            'trademark.registration_number' => ['nullable', 'string', 'max:100'],
            'trademark.mark_text' => ['nullable', 'string', 'max:500'],
            'trademark.nice_classes' => ['nullable', 'array'], 'trademark.nice_classes.*' => ['integer', 'between:1,45'],
            'trademark.goods_services' => ['nullable', 'string', 'max:20000'],
            'trademark.filing_date' => ['nullable', 'date'], 'trademark.registration_date' => ['nullable', 'date'],
            'trademark.renewal_due_date' => ['nullable', 'date'],
        ];
    }
}
