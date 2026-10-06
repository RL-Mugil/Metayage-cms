<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * decimal(12,2) silently rounds Gratuity Provision's 1/24 = 4.1667% down
     * to 4.17% on insert — a real, if small, miscalculation (₹4.80/month on
     * a ₹144,000 Basic). Widening to 4 decimal places before the real-formula
     * reseed (2026_10_11_000003) fixes it for good.
     */
    public function up(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            $table->decimal('value', 12, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            $table->decimal('value', 12, 2)->nullable()->change();
        });
    }
};
