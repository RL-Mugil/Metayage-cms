<?php

namespace App\Http\Requests;

use App\Models\ZohoQuoteExport;
use Illuminate\Foundation\Http\FormRequest;

class SendZohoQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ZohoQuoteExport::class);
    }

    public function rules(): array
    {
        return ['customer_id' => ['required', 'string', 'regex:/^[0-9]{1,80}$/'],
            'version' => ['required', 'string', 'size:64'], 'review_confirmed' => ['accepted']];
    }
}
