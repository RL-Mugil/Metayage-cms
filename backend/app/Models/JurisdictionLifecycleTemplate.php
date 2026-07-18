<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JurisdictionLifecycleTemplate extends Model
{
    protected $fillable = [
        'jurisdiction', 'service_code', 'name', 'version', 'effective_from',
        'effective_to', 'is_active', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function stages(): HasMany
    {
        return $this->hasMany(JurisdictionLifecycleStage::class)->orderBy('sequence_order');
    }
}
