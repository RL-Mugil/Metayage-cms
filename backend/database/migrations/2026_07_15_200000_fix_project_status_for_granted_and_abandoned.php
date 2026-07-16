<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Granted patents that were imported as "In Progress" → Completed
        DB::statement("
            UPDATE projects
            SET status = 'Completed'
            WHERE patent_granted = true
              AND status = 'In Progress'
              AND deleted_at IS NULL
        ");

        // All stages on granted projects → Completed (no stage should be pending/in-progress once granted)
        DB::statement("
            UPDATE project_stages
            SET status = 'Completed',
                actual_end_at = COALESCE(actual_end_at, updated_at)
            WHERE project_id IN (
                SELECT id FROM projects WHERE patent_granted = true AND deleted_at IS NULL
            )
            AND status != 'Completed'
        ");
    }

    public function down(): void
    {
        // Not reversible without original data
    }
};
