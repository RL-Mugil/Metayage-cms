<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collections: payment reminders drafted by the portal and sent only after the Client Manager or
 * Accounts approves; promise-to-pay dates and a history of follow-ups on each invoice; a per-client
 * pause and preferred recipients (kept apart from the clients table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patent_invoices_in', function (Blueprint $table) {
            $table->date('promised_on')->nullable()->after('status_note_at');
            $table->string('promise_note', 500)->nullable()->after('promised_on');
        });

        Schema::create('invoice_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patent_invoice_in_id')->constrained('patent_invoices_in')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500);
            $table->date('promised_on')->nullable();
            $table->timestamps();
        });

        Schema::create('collection_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->smallInteger('band');                       // days after due (negative: before due)
            $table->json('invoice_ids');
            $table->decimal('amount', 15, 2);                   // INR
            $table->json('recipients');                         // [{name, email}]
            $table->string('subject', 255);
            $table->text('body');
            $table->string('status', 20)->default('draft');      // draft | sent | skipped | failed
            $table->boolean('escalated')->default(false);
            $table->foreignId('sent_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['client_id', 'band', 'status']);
        });

        Schema::create('collection_client_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained('clients')->cascadeOnDelete();
            $table->date('paused_until')->nullable();
            $table->json('recipients')->nullable();             // overrides the client's billing contacts
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_client_settings');
        Schema::dropIfExists('collection_reminders');
        Schema::dropIfExists('invoice_follow_ups');
        Schema::table('patent_invoices_in', function (Blueprint $table) {
            $table->dropColumn(['promised_on', 'promise_note']);
        });
    }
};
