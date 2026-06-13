<?php

return [
    /*
     * Professional Tax: flat monthly amount (Karnataka/Maharashtra slab for gross > ₹15,001).
     * Set PAYROLL_PT_MONTHLY in .env to override (e.g. 0 for states with no PT).
     * Full state-dependent slabs require adding employees.work_state; this is a v1 simplification.
     */
    'pt_monthly' => (float) env('PAYROLL_PT_MONTHLY', 200.0),
];
