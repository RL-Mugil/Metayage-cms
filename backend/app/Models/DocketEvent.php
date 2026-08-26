<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

class DocketEvent extends Model
{
    use BelongsToFirm;

    protected $fillable = [
        'project_id', 'ip_record_id', 'patent_application_id', 'event_type', 'event_date', 'notes', 'created_by',
    ];

    protected $casts = [
        'event_date' => 'date',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function application()
    {
        return $this->belongsTo(PatentApplication::class, 'patent_application_id');
    }

    public function ipRecord()
    {
        return $this->belongsTo(IpRecord::class);
    }

    public function deadlines()
    {
        return $this->hasMany(DocketDeadline::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
