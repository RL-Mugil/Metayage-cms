<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceSetting extends Model
{
    protected $table = 'attendance_settings';

    protected $fillable = [
        'max_sessions_per_day',
        'work_start_time',
        'work_end_time',
        'lunch_start',
        'lunch_end',
        'standard_hours_minutes',
    ];

    /** Always return (or seed) the single settings row. */
    public static function get(): self
    {
        return static::firstOrCreate([], [
            'max_sessions_per_day'   => 3,
            'work_start_time'        => '09:30:00',
            'work_end_time'          => '18:00:00',
            'lunch_start'            => '13:00:00',
            'lunch_end'              => '14:30:00',
            'standard_hours_minutes' => 480,
        ]);
    }
}
