<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rows were seeded with explicit IDs, leaving Postgres sequences behind
     * the actual max(id). Any subsequent INSERT then collides
     * ("duplicate key value violates unique constraint ..._pkey").
     * Resync every id sequence to max(id) across all tables.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tables = DB::select("
            SELECT c.table_name
            FROM information_schema.columns c
            JOIN information_schema.tables t
              ON t.table_name = c.table_name AND t.table_schema = c.table_schema
            WHERE c.table_schema = 'public'
              AND c.column_name = 'id'
              AND c.column_default LIKE 'nextval%'
              AND t.table_type = 'BASE TABLE'
        ");

        foreach ($tables as $t) {
            $table = $t->table_name;
            DB::statement("
                SELECT setval(
                    pg_get_serial_sequence('\"{$table}\"', 'id'),
                    GREATEST(COALESCE((SELECT MAX(id) FROM \"{$table}\"), 1), 1)
                )
            ");
        }
    }

    public function down(): void
    {
        // Nothing to revert — sequence positions only move forward.
    }
};
