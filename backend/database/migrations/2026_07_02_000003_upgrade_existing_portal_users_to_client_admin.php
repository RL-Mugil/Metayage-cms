<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data migration: existing portal users were created before the
     * client/client_admin split. They were each the primary contact of
     * their client (clients.portal_user_id), so they become client_admin.
     */
    public function up(): void
    {
        $primaryUserIds = DB::table('clients')
            ->whereNotNull('portal_user_id')
            ->pluck('portal_user_id');

        DB::table('users')
            ->whereIn('id', $primaryUserIds)
            ->where('role', 'client')
            ->update(['role' => 'client_admin']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'client_admin')->update(['role' => 'client']);
    }
};
