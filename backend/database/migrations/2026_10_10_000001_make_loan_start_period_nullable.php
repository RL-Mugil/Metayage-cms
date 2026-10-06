<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A self-requested loan (Loan::STATUS_REQUESTED) has no start_period
        // until payroll-access approval sets one.
        Schema::table('loans', function (Blueprint $table) {
            $table->date('start_period')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->date('start_period')->nullable(false)->change();
        });
    }
};
