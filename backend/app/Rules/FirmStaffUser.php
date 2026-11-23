<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The value is the id of a firm employee login (User::FIRM_STAFF_ROLES), never a client or
 * inventor login. Used for a case's attorney, client managers and patent engineer.
 * $unchanged: the value already saved, which an edit may send back as it is.
 */
class FirmStaffUser implements ValidationRule
{
    public function __construct(private readonly mixed $unchanged = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if ($this->unchanged !== null && (string) $value === (string) $this->unchanged) {
            return;
        }
        $isStaff = is_numeric($value)
            && User::whereKey((int) $value)->whereIn('role', User::FIRM_STAFF_ROLES)->exists();
        if (! $isStaff) {
            $fail('Choose one of the firm\'s employees.');
        }
    }
}
