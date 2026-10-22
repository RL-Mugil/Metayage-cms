<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpsIncident extends Model
{
    protected $table = 'ops_incidents';

    protected $guarded = [];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'reported_at' => 'datetime',
        'recovery_mailed_at' => 'datetime',
    ];
}
