<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('feedback_entries')->delete();
        DB::table('reminders')->delete();
    }

    public function down(): void
    {
        // Data removed intentionally — no rollback
    }
};
