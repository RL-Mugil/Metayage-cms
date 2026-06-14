<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'salary')) {
            if (DB::getDriverName() === 'sqlite') {
                Schema::table('employees', function ($table) {
                    $table->text('salary')->nullable()->change();
                });
            } else {
                // USING clause converts existing numeric values to text before re-typing.
                DB::statement('ALTER TABLE employees ALTER COLUMN salary TYPE TEXT USING CAST(salary AS TEXT)');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'salary')) {
            // Reverse: drop encrypted values (they can't cast back to numeric) then re-add column.
            DB::statement('ALTER TABLE employees DROP COLUMN salary');
            Schema::table('employees', function ($table) {
                $table->decimal('salary', 12, 2)->nullable()->after('bank_ifsc_code');
            });
        }
    }
};
