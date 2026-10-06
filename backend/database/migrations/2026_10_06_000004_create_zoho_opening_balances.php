<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoho_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 80);
            $table->string('zoho_contact_id', 80);
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('location_id', 80)->default('default');
            $table->date('balance_date');
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('currency', 5)->default('INR');
            $table->string('source', 40)->default('Zoho Opening Balance');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'zoho_contact_id', 'location_id', 'balance_date'], 'zoho_opening_balances_identity');
            $table->index(['scope', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_opening_balances');
    }
};
