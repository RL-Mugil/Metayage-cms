<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Recreate the quick-access staff test accounts (deleted during Staff
     * Users testing). Upserts by email so it is safe to re-run and never
     * duplicates. Postgres assigns the ids.
     */
    public function up(): void
    {
        $accounts = [
            ['name' => 'Test SuperAdmin', 'email' => 'test.superadmin@myipstrategy.com', 'role' => 'super_admin'],
            ['name' => 'Test Director',   'email' => 'test.partner@myipstrategy.com',    'role' => 'partner'],
            ['name' => 'Test Attorney',   'email' => 'test.manager@myipstrategy.com',    'role' => 'manager'],
            ['name' => 'Test HR',         'email' => 'test.hr@myipstrategy.com',         'role' => 'hr'],
            ['name' => 'Test Accountant', 'email' => 'test.finance@myipstrategy.com',    'role' => 'finance'],
            ['name' => 'Test Analyst',    'email' => 'test.associate@myipstrategy.com',  'role' => 'associate'],
            ['name' => 'Test Paralegal',  'email' => 'test.paralegal@myipstrategy.com',  'role' => 'paralegal'],
        ];

        foreach ($accounts as $a) {
            $exists = DB::table('users')->where('email', $a['email'])->exists();
            if ($exists) {
                DB::table('users')->where('email', $a['email'])->update([
                    'password'   => Hash::make('Test@1234'),
                    'role'       => $a['role'],
                    'status'     => 'Active',
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('users')->insert([
                    'name'       => $a['name'],
                    'email'      => $a['email'],
                    'password'   => Hash::make('Test@1234'),
                    'role'       => $a['role'],
                    'status'     => 'Active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // No-op — do not remove accounts that may be in use.
    }
};
