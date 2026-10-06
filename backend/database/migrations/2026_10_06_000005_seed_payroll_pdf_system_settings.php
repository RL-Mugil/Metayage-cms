<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PF/ESI toggles default to 'true' — preserves today's behavior exactly
     * (both were always-on, hardcoded). Letterhead defaults are seeded from
     * the firm's real payslip so the PDF is correct out of the box; editable
     * later via Settings → Payroll.
     */
    public function up(): void
    {
        $now = now();
        $defaults = [
            'payroll_pf_enabled' => 'true',
            'payroll_esi_enabled' => 'true',
            'payroll_company_name' => 'Metayage Private Limited',
            'payroll_company_address' => '207, EGPI Arcadia 32, Banaswadi Main Road, Jai Bharath Nagar, Bangalore, Karnataka, 560033, India',
            'payroll_company_logo_url' => '',
        ];

        foreach ($defaults as $key => $value) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', [
            'payroll_pf_enabled', 'payroll_esi_enabled',
            'payroll_company_name', 'payroll_company_address', 'payroll_company_logo_url',
        ])->delete();
    }
};
