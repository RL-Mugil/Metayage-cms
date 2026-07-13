<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('project_stages')
            ->where('stage_name', 'Provisional Application')
            ->update(['stage_name' => 'Provisional or Complete Application']);
    }

    public function down(): void
    {
        DB::table('project_stages')
            ->where('stage_name', 'Provisional or Complete Application')
            ->update(['stage_name' => 'Provisional Application']);
    }
};
