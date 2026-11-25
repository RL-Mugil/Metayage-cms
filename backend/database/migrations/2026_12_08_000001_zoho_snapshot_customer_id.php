<?php

use App\Models\ZohoSnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Zoho customer of a payment or credit note, in plain text: snapshot bodies are encrypted at
 * rest, so they cannot be searched. The client ledger finds a client's advances (payments applied
 * to none of its invoices) by this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_snapshots', function (Blueprint $table) {
            $table->string('zoho_customer_id', 40)->nullable()->after('remote_id');
            $table->index(['resource', 'zoho_customer_id']);
        });

        ZohoSnapshot::withTrashed()->whereIn('resource', ['customerpayments', 'creditnotes'])->orderBy('id')
            ->chunkById(200, function ($snapshots) {
                foreach ($snapshots as $snapshot) {
                    $customer = ZohoSnapshot::customerOf($snapshot);
                    if ($customer !== null) {
                        DB::table('zoho_snapshots')->where('id', $snapshot->id)->update(['zoho_customer_id' => $customer]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('zoho_snapshots', function (Blueprint $table) {
            $table->dropIndex(['resource', 'zoho_customer_id']);
            $table->dropColumn('zoho_customer_id');
        });
    }
};
