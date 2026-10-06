<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanRepayment extends Model
{
    public const STATUS_SCHEDULED = 'Scheduled';
    public const STATUS_DEDUCTED = 'Deducted';

    protected $fillable = [
        'loan_id',
        'payslip_id',
        'installment_amount',
        'period',
        'status',
    ];

    protected $casts = [
        'period' => 'date',
        'installment_amount' => 'decimal:2',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
