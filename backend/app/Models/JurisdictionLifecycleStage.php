<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JurisdictionLifecycleStage extends Model
{
    protected $fillable = [
        'jurisdiction_lifecycle_template_id', 'stage_code', 'stage_name',
        'sequence_order', 'target_duration_days', 'gate_criteria',
    ];

    protected $casts = ['gate_criteria' => 'array'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(JurisdictionLifecycleTemplate::class, 'jurisdiction_lifecycle_template_id');
    }
}
