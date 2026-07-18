<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceTransitionRule extends Model
{
    protected $fillable = [
        'jurisdiction', 'from_service_code', 'to_service_code', 'required_event_type',
        'required_application_status', 'description', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];
}
