<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Form16Document extends Model
{
    public const STATUS_REQUESTED = 'Requested'; // employee asked for it; no document generated yet
    public const STATUS_MISSING_PAN = 'MissingPan'; // can't generate — employee has no PAN on file
    public const STATUS_PART_B_ONLY = 'PartBOnly';
    public const STATUS_MERGED = 'Merged';
    public const STATUS_FAILED = 'Failed';

    protected $fillable = [
        'employee_id',
        'financial_year',
        'part_a_path',
        'part_b_path',
        'merged_path',
        'pan_matched',
        'status',
        'published_at',
    ];

    protected $casts = [
        'pan_matched' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
