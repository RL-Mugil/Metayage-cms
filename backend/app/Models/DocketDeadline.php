<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

class DocketDeadline extends Model
{
    use BelongsToFirm;

    protected $fillable = [
        'docket_event_id', 'project_id', 'ip_record_id', 'patent_application_id',
        'title', 'legal_basis', 'due_date', 'extended_due_date',
        'source_type', 'rule_code', 'rule_version', 'calculation_trace',
        'deadline_rule_definition_id', 'risk_level',
        'review_status', 'reviewed_by', 'reviewed_at',
        'status', 'completed_at', 'notes', 'statutory_due_date', 'operational_adjustment',
        'supersession_status', 'superseded_by_id',
    ];

    protected $casts = [
        'due_date'          => 'date',
        'statutory_due_date'=> 'date',
        'extended_due_date' => 'date',
        'completed_at'      => 'datetime',
        'calculation_trace' => 'array',
        'reviewed_at'       => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(DocketEvent::class, 'docket_event_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function ipRecord()
    {
        return $this->belongsTo(IpRecord::class);
    }

    public function application()
    {
        return $this->belongsTo(PatentApplication::class, 'patent_application_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function ruleDefinition()
    {
        return $this->belongsTo(DeadlineRuleDefinition::class, 'deadline_rule_definition_id');
    }
}
