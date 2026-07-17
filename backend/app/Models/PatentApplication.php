<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

class PatentApplication extends Model
{
    use BelongsToFirm;

    public const LEGAL_STATUSES = [
        'Pending', 'Published', 'Under Examination', 'Granted',
        'Lapsed', 'Refused', 'Abandoned', 'Withdrawn',
    ];

    protected $fillable = [
        'client_id', 'invention_family_id', 'application_number', 'title',
        'priority_date', 'filing_date', 'publication_date', 'rfe_filed_date',
        'grant_number', 'grant_date', 'legal_status', 'jurisdiction',
    ];

    protected $casts = [
        'priority_date'    => 'date',
        'filing_date'      => 'date',
        'publication_date' => 'date',
        'rfe_filed_date'   => 'date',
        'grant_date'       => 'date',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function inventionFamily()
    {
        return $this->belongsTo(InventionFamily::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function renewals()
    {
        return $this->hasMany(RenewalSchedule::class)->orderBy('renewal_year');
    }

    public function events()
    {
        return $this->hasMany(DocketEvent::class)->orderByDesc('event_date');
    }

    public function deadlines()
    {
        return $this->hasMany(DocketDeadline::class)->orderBy('due_date');
    }
}
