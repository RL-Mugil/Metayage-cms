<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contractors')) {
            return;
        }

        Schema::create('contractors', function (Blueprint $table) {
            $table->id();
            $table->string('contractor_code')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            // PII — encrypted at rest via the EncryptedSafe cast (same pattern as Employee).
            $table->text('pan_tax_id')->nullable();
            $table->string('gstin')->nullable();
            $table->text('bank_account_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->text('bank_ifsc_code')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('tds_section')->default('194J'); // 194J (professional) or 194C (contract)
            $table->string('status')->default('Active'); // Active, Inactive
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractors');
    }
};
