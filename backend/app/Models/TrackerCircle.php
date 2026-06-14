<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackerCircle extends Model
{
    protected $fillable = ['name', 'slug', 'description'];

    protected static function booted(): void
    {
        static::deleting(function (TrackerCircle $circle) {
            $circle->members()->detach();
        });
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'tracker_circle_members', 'circle_id', 'user_id')
            ->withTimestamps();
    }

    public function rows()
    {
        return $this->hasMany(TrackerRow::class, 'circle_id');
    }
}
