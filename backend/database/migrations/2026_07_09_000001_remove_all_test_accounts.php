<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Hard-delete every user whose email matches test.*@myipstrategy.com.
     * Covers all staff test accounts AND client/client_admin test accounts.
     * Cascades: Sanctum tokens, client_contacts rows.
     * Never touches real users — the pattern is unique to seeded test data.
     */
    public function up(): void
    {
        $pattern = 'test.%@myipstrategy.com';

        $ids = DB::table('users')
            ->where('email', 'like', $pattern)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Revoke Sanctum tokens so any active sessions are invalidated immediately.
        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->whereIn('tokenable_id', $ids)
            ->delete();

        // Remove client_contacts rows that map these users into a client portal.
        $emails = DB::table('users')
            ->where('email', 'like', $pattern)
            ->pluck('email');

        DB::table('client_contacts')
            ->whereIn('email', $emails)
            ->delete();

        // Delete the users themselves.
        DB::table('users')
            ->where('email', 'like', $pattern)
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — test accounts are not restored on rollback.
    }
};
