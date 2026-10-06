<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Employer TAN — needed in the Form 24Q header. Editable via Settings → Payroll. */
    public function up(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'payroll_company_tan'],
            ['value' => '', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'payroll_company_tan')->delete();
    }
};
