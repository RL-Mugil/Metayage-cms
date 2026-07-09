<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectCode extends Model
{
    protected $fillable = ['type', 'code', 'description', 'created_by_id'];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
