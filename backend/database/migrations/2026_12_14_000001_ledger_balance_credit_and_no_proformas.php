<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Client ledgers:
 * - money received and not yet applied to an invoice is called "Balance credit" (was "Advance",
 *   reference "… · unused credit");
 * - proformas (unpaid quotes) are never ledger lines: the Quote lines are removed and those
 *   clients' running balances rebuilt.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('client_ledger')->where('document_type', 'Advance')->update(['document_type' => 'Balance credit']);
        DB::table('client_ledger')->where('document_reference', 'like', '% · unused credit')
            ->update(['document_reference' => DB::raw("REPLACE(document_reference, ' · unused credit', ' · balance credit')")]);
        DB::table('client_ledger')->where('notes', 'Received, not yet applied to an invoice (advance)')
            ->update(['notes' => 'Received, not yet applied to an invoice (balance credit)']);

        $clients = DB::table('client_ledger')->where('document_type', 'Quote')->distinct()->pluck('client_id')->map(fn ($id) => (int) $id)->all();
        DB::table('client_ledger')->where('document_type', 'Quote')->delete();
        $sync = app(\App\Services\ClientLedgerSync::class);
        foreach ($clients as $clientId) {
            $sync->rebalance($clientId);
        }
    }

    public function down(): void
    {
        DB::table('client_ledger')->where('document_type', 'Balance credit')->update(['document_type' => 'Advance']);
        DB::table('client_ledger')->where('document_reference', 'like', '% · balance credit')
            ->update(['document_reference' => DB::raw("REPLACE(document_reference, ' · balance credit', ' · unused credit')")]);
    }
};
