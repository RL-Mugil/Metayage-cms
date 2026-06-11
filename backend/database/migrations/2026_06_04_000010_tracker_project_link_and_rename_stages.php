<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add project_id FK to tracker_rows
        Schema::table('tracker_rows', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('circle_id')
                ->constrained('projects')->nullOnDelete();
        });

        // Rename pipeline stages: Opposition → Object received, Registered → Granted
        DB::table('project_stages')
            ->where('stage_name', 'Opposition')
            ->update(['stage_name' => 'Object received']);

        DB::table('project_stages')
            ->where('stage_name', 'Registered')
            ->update(['stage_name' => 'Granted']);
    }

    public function down(): void
    {
        Schema::table('tracker_rows', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });

        DB::table('project_stages')
            ->where('stage_name', 'Object received')
            ->update(['stage_name' => 'Opposition']);

        DB::table('project_stages')
            ->where('stage_name', 'Granted')
            ->update(['stage_name' => 'Registered']);
    }
};
