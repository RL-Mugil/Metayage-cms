<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - clients.referred_by_code is widened to the client_code length (legacy codes are longer than 10).
 * - client_contacts.designation records a portal user's position at the client
 *   (Manager, CFO, CTO, Accounts Person, Other), which also fixes their portal access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('referred_by_code', 50)->nullable()->change();
        });
        Schema::table('client_contacts', function (Blueprint $table) {
            $table->string('designation', 50)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('client_contacts', function (Blueprint $table) {
            $table->dropColumn('designation');
        });
        // referred_by_code stays at 50: narrowing could truncate saved codes.
    }
};
