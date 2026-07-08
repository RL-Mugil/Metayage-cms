<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill clients.portal_user_id from existing portal users linked by
     * client_contacts.email. Prefer client_admin over plain client users.
     */
    public function up(): void
    {
        $clients = DB::table('clients')
            ->select('id')
            ->whereNull('portal_user_id')
            ->get();

        foreach ($clients as $client) {
            $portalUserId = DB::table('client_contacts as cc')
                ->join('users as u', 'u.email', '=', 'cc.email')
                ->where('cc.client_id', $client->id)
                ->whereIn('u.role', ['client_admin', 'client'])
                ->orderByRaw("CASE WHEN u.role = 'client_admin' THEN 0 ELSE 1 END")
                ->orderBy('u.id')
                ->value('u.id');

            if ($portalUserId) {
                DB::table('clients')
                    ->where('id', $client->id)
                    ->update([
                        'portal_user_id' => $portalUserId,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('clients')
            ->whereNotNull('portal_user_id')
            ->update([
                'portal_user_id' => null,
                'updated_at' => now(),
            ]);
    }
};
