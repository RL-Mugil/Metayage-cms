<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['fee_arrangement', 'confidentiality_level']);
            $table->date('idf_received_date')->nullable()->after('hard_deadline');
            $table->date('advance_payment_date')->nullable()->after('idf_received_date');
            $table->date('partial_payment_date')->nullable()->after('advance_payment_date');
            $table->date('full_payment_date')->nullable()->after('partial_payment_date');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['idf_received_date', 'advance_payment_date', 'partial_payment_date', 'full_payment_date']);
            $table->string('fee_arrangement')->nullable();
            $table->string('confidentiality_level')->nullable();
        });
    }
};
