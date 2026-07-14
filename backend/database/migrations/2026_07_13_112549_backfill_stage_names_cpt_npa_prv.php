<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // CPT / NPA: "Filed" → "Filed — Waiting for FER or Grant"
        DB::statement("
            UPDATE project_stages ps
            SET    stage_name = 'Filed — Waiting for FER or Grant',
                   updated_at = NOW()
            WHERE  ps.stage_name = 'Filed'
            AND    EXISTS (
                SELECT 1 FROM projects p
                WHERE  p.id = ps.project_id
                AND    UPPER(p.service_code) IN ('CPT', 'NPA')
                AND    p.deleted_at IS NULL
            )
        ");

        // CPT / NPA / PRV: "Drafting in Progress" → "Drafting"
        DB::statement("
            UPDATE project_stages ps
            SET    stage_name = 'Drafting',
                   updated_at = NOW()
            WHERE  ps.stage_name = 'Drafting in Progress'
            AND    EXISTS (
                SELECT 1 FROM projects p
                WHERE  p.id = ps.project_id
                AND    UPPER(p.service_code) IN ('CPT', 'NPA', 'PRV')
                AND    p.deleted_at IS NULL
            )
        ");

        // CPT / NPA: "Draft Approved" → "Drafted"
        DB::statement("
            UPDATE project_stages ps
            SET    stage_name = 'Drafted',
                   updated_at = NOW()
            WHERE  ps.stage_name = 'Draft Approved'
            AND    EXISTS (
                SELECT 1 FROM projects p
                WHERE  p.id = ps.project_id
                AND    UPPER(p.service_code) IN ('CPT', 'NPA')
                AND    p.deleted_at IS NULL
            )
        ");
    }

    public function down(): void
    {
        DB::statement("
            UPDATE project_stages ps
            SET    stage_name = 'Filed',
                   updated_at = NOW()
            WHERE  ps.stage_name = 'Filed — Waiting for FER or Grant'
            AND    EXISTS (
                SELECT 1 FROM projects p
                WHERE  p.id = ps.project_id
                AND    UPPER(p.service_code) IN ('CPT', 'NPA')
                AND    p.deleted_at IS NULL
            )
        ");

        DB::statement("
            UPDATE project_stages ps
            SET    stage_name = 'Drafting in Progress',
                   updated_at = NOW()
            WHERE  ps.stage_name = 'Drafting'
            AND    EXISTS (
                SELECT 1 FROM projects p
                WHERE  p.id = ps.project_id
                AND    UPPER(p.service_code) IN ('CPT', 'NPA', 'PRV')
                AND    p.deleted_at IS NULL
            )
        ");

        DB::statement("
            UPDATE project_stages ps
            SET    stage_name = 'Draft Approved',
                   updated_at = NOW()
            WHERE  ps.stage_name = 'Drafted'
            AND    EXISTS (
                SELECT 1 FROM projects p
                WHERE  p.id = ps.project_id
                AND    UPPER(p.service_code) IN ('CPT', 'NPA')
                AND    p.deleted_at IS NULL
            )
        ");
    }
};
