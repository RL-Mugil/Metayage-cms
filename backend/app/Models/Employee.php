<?php

namespace App\Models;

use App\Casts\EncryptedSafe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'employee_code',
        'user_id',
        'full_name',
        'date_of_birth',
        'gender',
        'nationality',
        'marital_status',
        'blood_group',
        'emergency_contact',
        'personal_email',
        'work_email',
        'phone',
        'mobile',
        'current_address',
        'permanent_address',
        'aadhaar_ssn_encrypted',
        'pan_tax_id',
        'uan_pf_number',
        'esi_number',
        'bank_account_number',
        'bank_name',
        'bank_ifsc_code',
        'salary',
        'date_of_joining',
        'confirmation_date',
        'employment_type',
        'employment_status',
        'department_id',
        'designation_id',
        'reporting_manager_id',
        'dotted_line_manager_id',
        'work_location',
        'shift',
        'biometric_id',
        'photo_path',
        'resume_path',
        'id_documents',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'date_of_joining' => 'date',
        'confirmation_date' => 'date',
        'id_documents' => 'array',
        // PII encrypted at rest (tolerant of legacy plaintext on read)
        'aadhaar_ssn_encrypted' => EncryptedSafe::class,
        'pan_tax_id' => EncryptedSafe::class,
        'uan_pf_number' => EncryptedSafe::class,
        'esi_number' => EncryptedSafe::class,
        'bank_account_number' => EncryptedSafe::class,
        'bank_ifsc_code' => EncryptedSafe::class,
        'salary' => EncryptedSafe::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function reportingManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporting_manager_id');
    }

    public function dottedLineManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dotted_line_manager_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'assigned_to_employee_id');
    }

    public function expenseClaims(): HasMany
    {
        return $this->hasMany(ExpenseClaim::class);
    }

    public function grievances(): HasMany
    {
        return $this->hasMany(Grievance::class);
    }
}
