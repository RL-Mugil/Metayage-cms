<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('created_by_id')->nullable()->after('client_id')
                ->constrained('users')->nullOnDelete();
            $table->index('created_by_id');
        });

        // Backfill from the creation audit trail (earliest 'create' entry per case).
        DB::statement(<<<'SQL'
            UPDATE projects p
            SET created_by_id = a.user_id
            FROM (
                SELECT DISTINCT ON (subject_id) subject_id, user_id
                FROM audit_logs
                WHERE subject_type = 'Project' AND action = 'create' AND user_id IS NOT NULL
                ORDER BY subject_id, created_at
            ) a
            WHERE a.subject_id = p.id
              AND p.created_by_id IS NULL
              AND EXISTS (SELECT 1 FROM users u WHERE u.id = a.user_id)
        SQL);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_id');
        });
    }
};
