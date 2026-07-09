<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Restore the quick-access staff test accounts to Test@1234. Their
     * passwords drifted while the Reset Password feature was being exercised.
     * Only touches the known test.* seed accounts — never real users.
     */
    public function up(): void
    {
        $accounts = [
            'test.superadmin@myipstrategy.com' => 'super_admin',
            'test.partner@myipstrategy.com'    => 'partner',
            'test.manager@myipstrategy.com'    => 'manager',
            'test.hr@myipstrategy.com'         => 'hr',
            'test.finance@myipstrategy.com'    => 'finance',
            'test.associate@myipstrategy.com'  => 'associate',
            'test.paralegal@myipstrategy.com'  => 'paralegal',
        ];

        foreach ($accounts as $email => $role) {
            DB::table('users')->where('email', $email)->update([
                'password'   => Hash::make('Test@1234'),
                'status'     => 'Active',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No-op — passwords are not reverted.
    }
};
