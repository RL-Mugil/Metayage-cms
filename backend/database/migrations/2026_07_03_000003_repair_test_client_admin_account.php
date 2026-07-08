<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Data migration: provision the test.clientadmin quick-access account.
     * Links it (and the existing test.client account) to the same client
     * record via client_contacts so RBAC scoping works, and ensures that
     * client's portal is enabled so the portal middleware lets them in.
     */
    public function up(): void
    {
        $email = 'test.clientadmin@myipstrategy.com';

        // Reuse the client the existing test.client contact belongs to;
        // otherwise fall back to any client; otherwise skip (empty DB).
        $clientId = DB::table('client_contacts')
            ->where('email', 'test.client@myipstrategy.com')
            ->value('client_id')
            ?? DB::table('clients')->whereNull('deleted_at')->orderBy('id')->value('id');

        if (! $clientId) {
            return;
        }

        $userId = DB::table('users')->where('email', $email)->value('id');
        if ($userId) {
            DB::table('users')->where('id', $userId)->update(['role' => 'client_admin', 'status' => 'Active', 'password' => Hash::make('Test@1234')]);
        } else {
            $userId = DB::table('users')->insertGetId([
                'name'       => 'Test Client Admin',
                'email'      => $email,
                'password'   => Hash::make('Test@1234'),
                'role'       => 'client_admin',
                'status'     => 'Active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Contact row = RBAC link to the client
        if (! DB::table('client_contacts')->where('email', $email)->exists()) {
            DB::table('client_contacts')->insert([
                'client_id'  => $clientId,
                'name'       => 'Test Client Admin',
                'email'      => $email,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Portal must be enabled for the middleware to allow access
        DB::table('clients')->where('id', $clientId)->update([
            'portal_enabled'    => true,
            'portal_invited_at' => now(),
        ]);
    }

    public function down(): void
    {
        $email = 'test.clientadmin@myipstrategy.com';
        DB::table('client_contacts')->where('email', $email)->delete();
        DB::table('users')->where('email', $email)->delete();
    }
};
