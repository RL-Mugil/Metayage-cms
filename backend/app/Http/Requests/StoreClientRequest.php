<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\ClientPortalUsers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    use Concerns\ValidatesClientAddress;

    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Client::class);
    }

    public function rules(): array
    {
        return [
            'record_mode'          => 'nullable|in:new,existing',
            'client_code'          => 'nullable|required_if:record_mode,existing|string|max:50|unique:clients,client_code',
            'client_type'          => 'required|in:individual,organization',
            'nationality'          => 'nullable|string|max:100',
            'country'              => 'nullable|string|max:100',
            'has_gstin'            => 'boolean',
            'gstin'                => ['nullable', 'string', 'max:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/'],
            'legal_name'           => 'required|string|max:255',
            'entity_type'          => 'nullable|string|max:100',
            'entity_subtype'       => 'nullable|string|max:100',
            'fee_entity_tier'      => 'nullable|in:individual_startup_msme,large_entity_standard',
            'pan_number'           => 'nullable|string|max:10',
            'cin_number'           => 'nullable|string|max:21',
            'trade_name'           => 'nullable|string|max:255',
            'website'              => 'nullable|string|max:255',
            'contact_name'         => 'nullable|string|max:255',
            'contact_email'        => 'nullable|email|max:255',
            'phone'                => 'nullable|string|max:20',
            'address'              => 'nullable|string',
            'state'                => 'nullable|string|max:100',
            ...$this->addressRules(),
            'primary_jurisdiction' => 'nullable|string|max:10',
            'language_preference'  => 'nullable|string|max:50',
            'industry'             => 'nullable|string|max:100',
            'payment_terms'        => 'nullable|string|max:50',
            'billing_frequency'    => 'nullable|string|max:50',
            'communication_preference' => 'nullable|array',
            // Extra reminder day-thresholds on top of the firm-default cadence
            // (e.g. an extra 30-day-before renewal reminder), see
            // ReminderThresholdResolver::thresholdsFor().
            'reminder_cadence_override'   => 'nullable|array',
            'reminder_cadence_override.*' => 'integer|min:0|max:365',
            // Manually-configured "usually pays N days before due date" —
            // drives SendDeadlineRemindersCommand::scanPaymentAnomalies().
            'payment_clearance_pattern'            => 'nullable|array',
            'payment_clearance_pattern.lead_days'  => 'nullable|integer|min:0|max:365',
            // Only firm staff can manage a client (never a client or inventor login).
            'account_manager_id'   => ['nullable', Rule::exists('users', 'id')->whereIn('role', User::FIRM_STAFF_ROLES)],
            'bank_name'            => 'nullable|string|max:255',
            'bank_account'         => 'nullable|string|max:50',
            'bank_ifsc'            => 'nullable|string|max:20',
            'referred_by_code'     => ['nullable', 'string', 'max:50', $this->referrerRule()],
            'accounts_person'      => 'nullable|string|max:255',
            'remarks'              => 'nullable|string',
            'status'               => 'nullable|string|max:50',
            'circle'               => 'nullable|in:A,B',
            // Portal logins created with the client (see ClientPortalUsers). The Accounts
            // Person is a Finance login; the others take their access from the designation.
            'accounts_portal'                => 'nullable|array',
            'accounts_portal.email'          => 'required_with:accounts_portal|email|max:255|unique:users,email',
            'accounts_portal.phone'          => 'nullable|string|max:30',
            'accounts_portal.password'       => 'required_with:accounts_portal|string|min:8|max:100',
            'portal_users'                   => 'nullable|array|max:20',
            'portal_users.*.name'            => 'required|string|max:255',
            'portal_users.*.email'           => 'required|email|max:255|distinct|unique:users,email',
            'portal_users.*.phone'           => 'nullable|string|max:30',
            'portal_users.*.designation'     => ['required', Rule::in(ClientPortalUsers::DESIGNATIONS)],
            'portal_users.*.password'        => 'required|string|min:8|max:100',
            'portal_users.*.inventor'        => 'nullable|array',
        ];
    }

    private function referrerRule(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('clients', 'client_code');
    }
}
