<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoho_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('zoho_id', 40);
            $table->string('zoho_type', 10); // 'invoice' | 'quote'
            $table->string('zoho_contact_id', 40)->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('number', 80)->nullable();
            $table->string('status', 30)->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->nullable();
            $table->string('currency', 5)->default('INR');
            $table->date('txn_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('application_no', 150)->nullable();
            $table->string('patent_office', 10)->nullable();
            $table->string('match_source', 10)->nullable(); // 'uin' | 'docket' | null
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['zoho_type', 'zoho_id']);
            $table->index('client_id');
            $table->index('project_id');
            $table->index('number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_invoices');
    }
};
