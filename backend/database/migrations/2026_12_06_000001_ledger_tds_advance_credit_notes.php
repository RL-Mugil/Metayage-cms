<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What settled a register invoice: cash (a customer payment), TDS withheld by the client (not
 * cash in hand until it is deposited) or a Zoho credit note. The client ledger shows TDS in its
 * own column, and cash figures (collections) count only cash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('register_payments', function (Blueprint $table) {
            $table->string('kind', 20)->default('cash')->after('estimated');   // cash | tds | credit_note
            $table->index(['kind']);
        });
        Schema::table('client_ledger', function (Blueprint $table) {
            $table->decimal('tds', 15, 2)->default(0)->after('credit');
        });
    }

    public function down(): void
    {
        Schema::table('client_ledger', function (Blueprint $table) {
            $table->dropColumn('tds');
        });
        Schema::table('register_payments', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
