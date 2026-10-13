<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CpiBooking extends Model
{
    protected $fillable = ['start_utc', 'end_utc', 'name', 'email', 'organization', 'topic', 'visitor_timezone', 'status', 'manage_token_hash'];

    protected $hidden = ['manage_token_hash'];

    protected $casts = ['start_utc' => 'datetime', 'end_utc' => 'datetime'];
}
