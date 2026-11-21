<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventor particulars for IPO Form 1 section 4 (and later Forms 3/5), entered once
 * per inventor and reused across every case they are inventor-of-record on. Personal
 * fields are text because they are encrypted at rest (EncryptedSafe cast).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('full_name')->nullable();          // family name first, as the IPO asks
            $table->text('gender')->nullable();
            $table->text('age')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('country_of_residence', 100)->nullable();
            $table->text('address_house')->nullable();
            $table->text('address_street')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->text('pincode')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventor_profiles');
    }
};
