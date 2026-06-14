<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobPosting extends Model
{
    protected $fillable = ['title', 'dept', 'posted_date', 'applicants', 'status', 'description', 'employment_type'];

    protected $casts = [
        'posted_date' => 'date:Y-m-d',
    ];

    public function candidates(): HasMany
    {
        return $this->hasMany(JobCandidate::class);
    }
}
