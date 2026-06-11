<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts a column at rest, but tolerates legacy plaintext on read.
 *
 * Switching an existing plaintext column to Laravel's built-in `encrypted`
 * cast throws DecryptException on every legacy row. This cast instead tries
 * to decrypt and falls back to returning the raw value, so a column can be
 * migrated to encryption without a hard cutover. New writes are always
 * encrypted; the accompanying data migration backfills existing rows.
 */
class EncryptedSafe implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // Legacy plaintext that predates encryption — return as-is.
            return $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return Crypt::encryptString((string) $value);
    }
}
