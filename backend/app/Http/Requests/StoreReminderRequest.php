<?php

namespace App\Http\Requests;

use App\Models\Reminder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReminderRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('create', Reminder::class) === true; }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::in(['Deadline', 'Meeting', 'Follow-up', 'Renewal'])],
            'due_date' => ['required', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'scope' => ['required', Rule::in(['self', 'user', 'team'])],
            'assigned_user_id' => ['nullable', 'required_if:scope,user', 'integer', 'exists:users,id'],
            'docket_deadline_id' => ['nullable', 'integer', 'exists:docket_deadlines,id'],
        ];
    }
}
