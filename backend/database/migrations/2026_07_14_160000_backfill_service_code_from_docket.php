<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // For projects where service_code is null but docket_number is ≥12 chars,
        // extract service_code from chars 9-11 (format: {4 client}{3 seq}{2 country}{3 service}).
        DB::statement("
            UPDATE projects
            SET service_code = UPPER(SUBSTRING(docket_number FROM 10 FOR 3))
            WHERE service_code IS NULL
              AND docket_number IS NOT NULL
              AND LENGTH(docket_number) >= 12
        ");
    }

    public function down(): void
    {
        // Not reversible — we don't know which were originally null.
    }
};
