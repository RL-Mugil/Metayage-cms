<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $client = $this->route('id') ? \App\Models\Client::find($this->route('id')) : null;

        return $client
            ? $this->user()->can('update', $client)
            : in_array($this->user()->role, ['super_admin', 'partner', 'manager', 'hr', 'galvanizer']);
    }

    public function rules(): array
    {
        return [
            'client_type'          => 'sometimes|in:individual,organization',
            'nationality'          => 'nullable|string|max:100',
            'country'              => 'nullable|string|max:100',
            'has_gstin'            => 'boolean',
            'gstin'                => ['nullable', 'string', 'max:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/'],
            'legal_name'           => 'sometimes|required|string|max:255',
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
        ];
    }

    /** A referrer must be an existing client; a legacy code already saved on this client is kept as is. */
    private function referrerRule(): \Closure
    {
        $saved = $this->route('id') ? Client::whereKey($this->route('id'))->value('referred_by_code') : null;

        return function (string $attribute, mixed $value, \Closure $fail) use ($saved) {
            if (filled($value) && $value !== $saved && ! Client::where('client_code', $value)->exists()) {
                $fail('Pick the referring client from the list.');
            }
        };
    }
}
