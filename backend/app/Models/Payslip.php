<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'employee_name',
        'employee_code',
        'designation',
        'gross_salary',
        'lop_days',
        'lop_deduction',
        'basic',
        'hra',
        'special_allowance',
        'pf_employee',
        'esi_employee',
        'professional_tax',
        'tds',
        'total_deductions',
        'net_pay',
    ];

    protected $casts = [
        'gross_salary' => 'decimal:2',
        'lop_days' => 'decimal:1',
        'lop_deduction' => 'decimal:2',
        'basic' => 'decimal:2',
        'hra' => 'decimal:2',
        'special_allowance' => 'decimal:2',
        'pf_employee' => 'decimal:2',
        'esi_employee' => 'decimal:2',
        'professional_tax' => 'decimal:2',
        'tds' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_pay' => 'decimal:2',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
