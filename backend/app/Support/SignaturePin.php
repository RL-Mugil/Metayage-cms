<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * The 4-digit internal signature PIN used to sign reports, payslips and Bank Advice. It lives on
 * the login's employee record; a login with no employee record (a shared login such as
 * "Accounts @ Metayage") holds its own PIN and signs as that login.
 */
class SignaturePin
{
    /** Where this login's PIN is kept: its employee record, or the login itself. */
    public static function holder(User $user): Employee|User
    {
        return Employee::where('user_id', $user->id)->first() ?? $user;
    }

    public static function isSet(User $user): bool
    {
        return self::holder($user)->internal_signature_pin_hash !== null;
    }

    public static function verify(User $user, string $pin): bool
    {
        $hash = self::holder($user)->internal_signature_pin_hash;

        return $hash !== null && Hash::check($pin, $hash);
    }

    public static function set(User $user, string $pin): Employee|User
    {
        $holder = self::holder($user);
        $holder->internal_signature_pin_hash = Hash::make($pin);
        $holder->save();

        return $holder;
    }
}
