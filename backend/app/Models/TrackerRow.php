<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackerRow extends Model
{
    protected $fillable = [
        'circle_id', 'project_id', 'docket_number', 'client_name', 'record_type',
        'pcm_id', 'scm_id', 'pr_id', 'project_start_date', 'status',
        'delivery_due_date', 'payment_status', 'percentage_of_completion',
        'uin', 'sort_order',
    ];

    protected $casts = [
        'project_start_date' => 'date:Y-m-d',
        'delivery_due_date'  => 'date:Y-m-d',
        'percentage_of_completion' => 'integer',
    ];

    public function circle()
    {
        return $this->belongsTo(TrackerCircle::class, 'circle_id');
    }

    public function pcmUser()
    {
        return $this->belongsTo(User::class, 'pcm_id');
    }

    public function scmUser()
    {
        return $this->belongsTo(User::class, 'scm_id');
    }

    public function prUser()
    {
        return $this->belongsTo(User::class, 'pr_id');
    }
}
