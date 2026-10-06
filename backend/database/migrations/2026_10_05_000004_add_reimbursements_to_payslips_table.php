<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payslips', 'reimbursements')) {
            Schema::table('payslips', function (Blueprint $table) {
                $table->decimal('reimbursements', 12, 2)->default(0)->after('net_pay');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payslips', 'reimbursements')) {
            Schema::table('payslips', function (Blueprint $table) {
                $table->dropColumn('reimbursements');
            });
        }
    }
};
