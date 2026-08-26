<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReminderRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'completed' => ['sometimes', 'boolean'],
            'acknowledged' => ['sometimes', 'boolean'],
        ];
    }
}
