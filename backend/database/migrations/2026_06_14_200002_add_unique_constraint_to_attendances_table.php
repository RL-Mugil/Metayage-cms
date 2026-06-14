<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove any duplicate rows that would violate the new constraint before adding it.
        // Keep the earliest record for each (employee_id, attendance_date) pair.
        DB::statement("
            DELETE FROM attendances
            WHERE id NOT IN (
                SELECT MIN(id)
                FROM attendances
                GROUP BY employee_id, attendance_date
            )
        ");

        Schema::table('attendances', function (Blueprint $table) {
            $table->unique(['employee_id', 'attendance_date'], 'attendances_employee_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('attendances_employee_date_unique');
        });
    }
};
