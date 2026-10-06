<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    public const STATUS_REQUESTED = 'Requested'; // employee self-service request, awaiting payroll-access approval
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_CLOSED = 'Closed';
    public const STATUS_REJECTED = 'Rejected';

    protected $fillable = [
        'employee_id',
        'principal_amount',
        'monthly_installment',
        'start_period',
        'remaining_balance',
        'status',
        'notes',
        'given_by_id',
    ];

    protected $casts = [
        'start_period' => 'date',
        'principal_amount' => 'decimal:2',
        'monthly_installment' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function givenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by_id');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class)->orderByDesc('period');
    }
}
