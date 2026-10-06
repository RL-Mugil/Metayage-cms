<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the editable Professional Tax setting from today's env-configured
     * default so there's no behavior gap the day this ships — PayrollService
     * reads this system_settings row directly from now on, never config/payroll.php.
     */
    public function up(): void
    {
        $now = now();
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'payroll_pt_monthly'],
            ['value' => (string) config('payroll.pt_monthly', 200.0), 'created_at' => $now, 'updated_at' => $now]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'payroll_pt_monthly')->delete();
    }
};
