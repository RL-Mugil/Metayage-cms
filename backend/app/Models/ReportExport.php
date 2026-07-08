<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    protected $fillable = [
        'generated_by_id',
        'report_type',
        'report_name',
        'format',
        'filters',
        'row_count',
        'snapshot',
    ];

    protected $casts = [
        'filters' => 'array',
        'snapshot' => 'array',
    ];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_id');
    }
}
