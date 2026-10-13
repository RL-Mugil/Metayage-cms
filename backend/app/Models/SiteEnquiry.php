<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteEnquiry extends Model
{
    protected $fillable = [
        'source', 'type', 'name', 'email', 'organization', 'stage', 'domain', 'message',
        'ip_address', 'user_agent', 'mail_status', 'mail_error', 'status', 'payload',
    ];

    protected $casts = ['payload' => 'array'];
}
