<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payslip_components', 'category')) {
            Schema::table('payslip_components', function (Blueprint $table) {
                $table->string('category')->default('earning')->after('calc_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payslip_components', 'category')) {
            Schema::table('payslip_components', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }
};
