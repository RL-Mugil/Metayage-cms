<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent encryption: encrypt only plaintext values
        DB::table('employees')->whereNotNull('salary')->chunkById(100, function ($employees) {
            foreach ($employees as $emp) {
                // Skip if already encrypted (EncryptedSafe prefix detection)
                if (strpos($emp->salary, 'eyJ') === 0) {
                    continue;
                }
                if ($emp->salary === null) {
                    continue;
                }
                DB::table('employees')
                    ->where('id', $emp->id)
                    ->update(['salary' => Crypt::encryptString($emp->salary)]);
            }
        });
    }

    public function down(): void
    {
        // Decryption on rollback: restore plaintext values
        DB::table('employees')->whereNotNull('salary')->chunkById(100, function ($employees) {
            foreach ($employees as $emp) {
                try {
                    if (strpos($emp->salary, 'eyJ') === 0) {
                        $plaintext = Crypt::decryptString($emp->salary);
                        DB::table('employees')
                            ->where('id', $emp->id)
                            ->update(['salary' => $plaintext]);
                    }
                } catch (\Exception $e) {
                    // Skip values that can't be decrypted (assume already plaintext)
                }
            }
        });
    }
};
