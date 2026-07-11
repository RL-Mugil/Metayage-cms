<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('max_sessions_per_day')->default(6);
            $table->time('work_start_time')->default('09:30:00');   // late if arrived after this
            $table->time('work_end_time')->default('18:00:00');     // overtime if working past this
            $table->time('lunch_start')->default('13:00:00');
            $table->time('lunch_end')->default('14:30:00');
            $table->unsignedSmallInteger('standard_hours_minutes')->default(480); // 8 h
            $table->timestamps();
        });

        // Seed the single settings row
        DB::table('attendance_settings')->insert([
            'max_sessions_per_day'  => 6,
            'work_start_time'       => '09:30:00',
            'work_end_time'         => '18:00:00',
            'lunch_start'           => '13:00:00',
            'lunch_end'             => '14:30:00',
            'standard_hours_minutes'=> 480,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_settings');
    }
};
