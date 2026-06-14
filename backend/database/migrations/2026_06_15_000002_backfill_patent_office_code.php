<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill patent_office_code for all records where it is not set.
        // Default to 'IN' (Indian Patent Office) — matches the form default and
        // reflects that all pre-existing matters in this firm are Indian filings.
        // Records with a value already set are left untouched.
        DB::statement("
            UPDATE projects
            SET patent_office_code = 'IN'
            WHERE patent_office_code IS NULL OR TRIM(patent_office_code) = ''
        ");
    }

    public function down(): void
    {
        // Irreversible data backfill — down() is intentionally a no-op.
    }
};
