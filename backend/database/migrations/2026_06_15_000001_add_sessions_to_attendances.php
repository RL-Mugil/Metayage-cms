<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // JSON array of {in, out, duration_minutes} — supports up to 6 sessions per day
            $table->json('sessions')->nullable()->after('check_out');
        });

        // Backfill existing records: wrap existing check_in/check_out into sessions array
        DB::statement("
            UPDATE attendances
            SET sessions = CASE
                WHEN check_in IS NOT NULL AND check_out IS NOT NULL
                    THEN json_build_array(json_build_object('in', check_in::text, 'out', check_out::text, 'duration_minutes', duration_minutes))
                WHEN check_in IS NOT NULL
                    THEN json_build_array(json_build_object('in', check_in::text, 'out', NULL, 'duration_minutes', NULL))
                ELSE '[]'::json
            END
            WHERE sessions IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('sessions');
        });
    }
};
