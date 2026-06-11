<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_code')->unique();
            $table->string('company_name');
            $table->string('trade_name')->nullable();
            $table->string('entity_type')->default('Corporation');
            $table->string('tax_id')->nullable();
            $table->string('industry')->nullable();
            $table->string('primary_jurisdiction')->nullable();
            $table->json('secondary_jurisdictions')->nullable();
            $table->string('website')->nullable();
            $table->date('date_onboarded')->useCurrent();
            $table->foreignId('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('credit_limit', 15, 2)->default(0.00);
            $table->string('payment_terms')->default('Net 30');
            $table->string('currency_preference')->default('USD');
            $table->string('billing_frequency')->default('Per-project');
            $table->json('communication_preference')->nullable();
            $table->string('language_preference')->default('en');
            $table->string('sla_tier')->default('Standard');
            $table->string('status')->default('Active');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('department')->nullable();
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('preferred_language')->default('en');
            $table->json('notification_preferences')->nullable();
            $table->string('role_type')->default('Primary Contact'); // Primary, Technical, Billing, Legal, Authorized Signatory
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_client_id')->constrained('clients')->onDelete('cascade');
            $table->foreignId('subsidiary_client_id')->constrained('clients')->onDelete('cascade');
            $table->string('relationship_type')->default('Parent/Subsidiary');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_relationships');
        Schema::dropIfExists('client_contacts');
        Schema::dropIfExists('clients');
    }
};
