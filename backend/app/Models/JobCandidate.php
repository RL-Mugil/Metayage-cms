<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobCandidate extends Model
{
    protected $fillable = ['job_posting_id', 'name', 'role', 'email', 'phone', 'resume_url', 'stage', 'applied_label'];

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
