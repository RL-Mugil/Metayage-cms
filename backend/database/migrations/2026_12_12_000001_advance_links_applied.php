<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An earmarked advance applied in Zoho to the invoice made from its quote. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advance_quote_links', function (Blueprint $table) {
            $table->string('applied_zoho_invoice_id', 80)->nullable()->after('note');
            $table->timestamp('applied_at')->nullable()->after('applied_zoho_invoice_id');
            $table->foreignId('applied_by_id')->nullable()->after('applied_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('advance_quote_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applied_by_id');
            $table->dropColumn(['applied_zoho_invoice_id', 'applied_at']);
        });
    }
};
