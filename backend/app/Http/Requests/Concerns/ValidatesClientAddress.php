<?php

namespace App\Http\Requests\Concerns;

use App\Support\PostalAddress;
use Illuminate\Validation\Validator;

/**
 * The client's address in the IPO-form parts (house, street, city, state, country,
 * pincode). When the address block is submitted (the Client form always sends it):
 * an Indian client needs City, State and a 6-digit Pincode; a foreign client City and
 * Country. Requests that do not send the block (other API callers, status-only edits)
 * are not forced to.
 */
trait ValidatesClientAddress
{
    /** @return array<string, mixed> */
    protected function addressRules(): array
    {
        return [
            'address_house' => 'nullable|string|max:255',
            'address_street' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:120',
            'pincode' => 'nullable|string|max:12',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! collect(['address_house', 'address_street', 'city', 'pincode'])->contains(fn ($k) => $this->has($k))) {
                return;
            }
            $existing = method_exists($this, 'existingClient') ? $this->existingClient() : null;
            $country = $this->input('country', $this->input('nationality', $existing?->country ?? $existing?->nationality));
            $filled = fn (string $k) => trim((string) $this->input($k, $existing?->{$k} ?? '')) !== '';

            if (! $filled('city')) {
                $v->errors()->add('city', 'City is required.');
            }
            if (PostalAddress::isIndia($country)) {
                if (! $filled('state')) {
                    $v->errors()->add('state', 'State is required for an Indian client.');
                }
                $pin = preg_replace('/\s+/', '', (string) $this->input('pincode', $existing?->pincode ?? ''));
                if (! preg_match('/^\d{6}$/', $pin)) {
                    $v->errors()->add('pincode', 'Enter the 6-digit PIN code.');
                }
            } elseif (trim((string) $country) === '') {
                $v->errors()->add('country', 'Country is required.');
            }
        });
    }
}
