<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Payment tracking for renewal invoices raised via RenewalActionController's
// approve -> invoice -> proof -> confirm loop. patent_invoices_in.status stays
// as-is (Draft/Sent/Accepted/Cancelled etc.) — payment_status is a separate,
// dedicated lifecycle so the two don't collide.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patent_invoices_in', function (Blueprint $table) {
            $table->string('payment_status')->nullable()->after('status'); // Pending, Proof Submitted, Confirmed
            $table->foreignId('proof_document_id')->nullable()->after('payment_status')->constrained('documents')->nullOnDelete();
            $table->text('status_note')->nullable()->after('proof_document_id');
            $table->foreignId('status_note_by_id')->nullable()->after('status_note')->constrained('users')->nullOnDelete();
            $table->timestamp('status_note_at')->nullable()->after('status_note_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('patent_invoices_in', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proof_document_id');
            $table->dropConstrainedForeignId('status_note_by_id');
            $table->dropColumn(['payment_status', 'status_note', 'status_note_at']);
        });
    }
};
