<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices settled in the portal (marked paid / waived off) — kept even when Zoho has not
 * caught up yet — and the state of writing that settlement back to Zoho Books.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patent_invoices_in', function (Blueprint $table) {
            $table->string('settlement', 20)->nullable()->after('payment_status');            // paid | waived | waiver_requested
            $table->decimal('waived_amount', 15, 2)->default(0)->after('settlement');         // invoice currency (approved)
            $table->decimal('waiver_requested_amount', 15, 2)->nullable()->after('waived_amount');
            $table->foreignId('waiver_requested_by_id')->nullable()->after('waiver_requested_amount')->constrained('users')->nullOnDelete();
            $table->timestamp('waiver_requested_at')->nullable()->after('waiver_requested_by_id');
            $table->text('waiver_reason')->nullable()->after('waiver_requested_at');
            $table->foreignId('settled_by_id')->nullable()->after('waiver_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable()->after('settled_by_id');
            $table->string('zoho_writeback', 16)->nullable()->after('settled_at');            // pending | done | failed | not_applicable
            $table->string('zoho_writeback_error', 400)->nullable()->after('zoho_writeback');
            $table->index('settlement');
        });

        Schema::table('register_payments', function (Blueprint $table) {
            $table->string('source', 10)->default('zoho')->after('client_id');                 // zoho | portal
            $table->foreignId('recorded_by_id')->nullable()->after('source')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('register_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by_id');
            $table->dropColumn('source');
        });
        Schema::table('patent_invoices_in', function (Blueprint $table) {
            $table->dropIndex(['settlement']);
            $table->dropConstrainedForeignId('settled_by_id');
            $table->dropConstrainedForeignId('waiver_requested_by_id');
            $table->dropColumn(['settlement', 'waived_amount', 'waiver_requested_amount', 'waiver_requested_at', 'waiver_reason', 'settled_at', 'zoho_writeback', 'zoho_writeback_error']);
        });
    }
};
