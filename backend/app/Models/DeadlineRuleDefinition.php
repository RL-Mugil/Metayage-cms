<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeadlineRuleDefinition extends Model
{
    protected $fillable = [
        'rule_code', 'version', 'jurisdiction', 'right_type', 'event_type', 'title',
        'legal_basis', 'anchor_field', 'offset_unit', 'offset_value', 'outer_offset_value',
        'effective_from', 'effective_to', 'status', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'effective_from' => 'date', 'effective_to' => 'date', 'approved_at' => 'datetime',
    ];

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
