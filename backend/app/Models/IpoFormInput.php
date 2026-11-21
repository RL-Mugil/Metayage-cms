<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Saved paralegal input for an IPO form on one case (see ipo_form_inputs migration). */
class IpoFormInput extends Model
{
    protected $fillable = ['project_id', 'form_type', 'data', 'updated_by_id'];

    protected $casts = [
        'data' => 'encrypted:array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
