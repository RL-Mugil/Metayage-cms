<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $targets = [
        'employees' => ['aadhaar_ssn_encrypted', 'pan_tax_id', 'uan_pf_number', 'esi_number', 'bank_account_number', 'bank_ifsc_code'],
        'clients'   => ['bank_account', 'bank_ifsc'],
    ];

    public function up(): void
    {
        // 1. Widen to text — ciphertext is far longer than the source values.
        foreach ($this->targets as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($table, $columns) {
                foreach ($columns as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $t->text($col)->nullable()->change();
                    }
                }
            });
        }

        // 2. Backfill: encrypt any value that isn't already ciphertext.
        foreach ($this->targets as $table => $columns) {
            $existing = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            if (! $existing) {
                continue;
            }

            DB::table($table)->select(array_merge(['id'], $existing))->orderBy('id')->chunk(200, function ($rows) use ($table, $existing) {
                foreach ($rows as $row) {
                    $updates = [];
                    foreach ($existing as $col) {
                        $val = $row->$col;
                        if ($val === null || $val === '') {
                            continue;
                        }
                        // If it already decrypts, it's encrypted — leave it.
                        try {
                            Crypt::decryptString($val);
                            continue;
                        } catch (DecryptException) {
                            $updates[$col] = Crypt::encryptString((string) $val);
                        }
                    }
                    if ($updates) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Decrypt back to plaintext so the schema can be safely narrowed if needed.
        foreach ($this->targets as $table => $columns) {
            $existing = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            foreach ($existing as $col) {
                DB::table($table)->select(['id', $col])->orderBy('id')->chunk(200, function ($rows) use ($table, $col) {
                    foreach ($rows as $row) {
                        if ($row->$col === null || $row->$col === '') {
                            continue;
                        }
                        try {
                            DB::table($table)->where('id', $row->id)->update([$col => Crypt::decryptString($row->$col)]);
                        } catch (DecryptException) {
                            // already plaintext
                        }
                    }
                });
            }
        }
    }
};
