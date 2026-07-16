<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('docket_trak_ref')->nullable()->after('docket_number');
        });

        // Backfill from notes for already-imported DocketTrak rows
        DB::statement("
            UPDATE projects
            SET docket_trak_ref = SUBSTRING(notes FROM 'Imported from DocketTrak \(ref: ([^\)]+)\)')
            WHERE notes LIKE '%Imported from DocketTrak%'
              AND deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('docket_trak_ref');
        });
    }
};
