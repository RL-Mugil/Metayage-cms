<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Admin-configured Proof of Investments submission window — deadline + the payroll month it takes effect from. */
    public function up(): void
    {
        Schema::create('poi_windows', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('financial_year')->unique();
            $table->date('deadline_at');
            $table->date('effective_payroll_month');
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poi_windows');
    }
};
