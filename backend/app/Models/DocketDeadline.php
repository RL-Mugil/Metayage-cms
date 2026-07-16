<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocketDeadline extends Model
{
    protected $fillable = [
        'docket_event_id', 'project_id', 'patent_application_id',
        'title', 'legal_basis', 'due_date', 'extended_due_date',
        'status', 'completed_at', 'notes',
    ];

    protected $casts = [
        'due_date'          => 'date',
        'extended_due_date' => 'date',
        'completed_at'      => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(DocketEvent::class, 'docket_event_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function application()
    {
        return $this->belongsTo(PatentApplication::class, 'patent_application_id');
    }
}
