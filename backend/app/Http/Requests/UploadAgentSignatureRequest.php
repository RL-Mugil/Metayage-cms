<?php

namespace App\Http\Requests;

use App\Services\Ipo\AgentSignature;
use Illuminate\Foundation\Http\FormRequest;

/** A Registered Patent Agent's signature image: PNG or JPG, up to 2 MB. */
class UploadAgentSignatureRequest extends FormRequest
{
    /** The roles that edit employee records (same as UpdateEmployeeRequest). */
    public const ROLES = ['super_admin', 'hr', 'partner', 'finance'];

    public function authorize(): bool
    {
        return in_array($this->user()->role, self::ROLES, true);
    }

    public function rules(): array
    {
        return [
            'signature' => 'required|file|image|mimes:png,jpg,jpeg|mimetypes:image/png,image/jpeg|max:'.AgentSignature::MAX_KB,
        ];
    }

    public function messages(): array
    {
        return [
            'signature.max'       => 'The signature image must be 2 MB or smaller.',
            'signature.mimes'     => 'The signature must be a PNG or JPG image.',
            'signature.mimetypes' => 'The signature must be a PNG or JPG image.',
        ];
    }
}
