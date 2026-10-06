<?php

return [
    /*
     * Professional Tax seed default only. The live, editable value lives in the
     * `system_settings` table (key `payroll_pt_monthly`, managed via Settings →
     * Payroll) — PayrollService::currentProfessionalTax() reads that directly and
     * only falls back to this env value if that row is somehow missing.
     */
    'pt_monthly' => (float) env('PAYROLL_PT_MONTHLY', 200.0),
];
