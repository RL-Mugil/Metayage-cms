<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class SalaryComponent extends Model
{
    /** Sentinel percentage_of value meaning "the LOP-adjusted payable gross itself". */
    public const GROSS = '_GROSS_';

    /** 'earning' sums (with the remainder row) to payable gross, as today. */
    public const CATEGORY_EARNING = 'earning';

    /** 'ctc_addon' inflates displayed Total CTC only — never reduces Net Pay
     *  (employer-side cost, e.g. NPS or Gratuity Provision). */
    public const CATEGORY_CTC_ADDON = 'ctc_addon';

    /**
     * For calc_type = 'employee_field', `percentage_of` is repurposed to hold
     * one of these Employee column names instead of a component code — the
     * amount is read directly off the employee (e.g. declared Base Pay or
     * HRA), not derived from gross or another component. Whitelisted here so
     * PayrollService never resolves an arbitrary/unvetted column name.
     */
    public const EMPLOYEE_FIELDS = ['base_pay_monthly', 'hra_monthly'];

    protected $fillable = [
        'name', 'code', 'calc_type', 'category', 'value', 'percentage_of', 'sequence', 'active',
    ];

    protected $casts = [
        // 4 places, not 2 — Gratuity Provision's real rate is 1/24 = 4.1667%;
        // decimal:2 would silently round that to 4.17% (a real, if small,
        // miscalculation) every time the model reads the column back.
        'value'    => 'decimal:4',
        'sequence' => 'integer',
        'active'   => 'boolean',
    ];

    /**
     * The template PayrollService walks on every payslip computation. Deliberately
     * NOT cached — this table is at most a handful of rows, so a direct query costs
     * nothing, and caching an Eloquent Collection here previously showed real
     * cross-process unserialization failures (__PHP_Incomplete_Class) against this
     * app's Redis cache store. Correctness on a financial computation path is worth
     * far more than skipping a trivial indexed query.
     */
    public static function activeOrdered(): Collection
    {
        return static::where('active', true)->orderBy('sequence')->get();
    }
}
