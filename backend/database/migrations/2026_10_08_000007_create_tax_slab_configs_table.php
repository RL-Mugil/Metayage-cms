<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tax_slab_configs')) {
            return;
        }

        Schema::create('tax_slab_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('financial_year'); // e.g. 2026 means FY2026-27
            $table->string('regime'); // old, new
            // Ordered [{upto, rate}] array, e.g. [{"upto":400000,"rate":0},{"upto":800000,"rate":5},...,{"upto":null,"rate":30}]
            $table->json('slabs_json');
            $table->decimal('standard_deduction', 12, 2)->default(0);
            $table->decimal('rebate_87a_limit', 12, 2)->default(0);
            $table->decimal('rebate_87a_amount', 12, 2)->default(0);
            $table->decimal('cess_rate', 5, 2)->default(4.00); // Health & Education Cess %
            $table->timestamps();

            $table->unique(['financial_year', 'regime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_slab_configs');
    }
};
