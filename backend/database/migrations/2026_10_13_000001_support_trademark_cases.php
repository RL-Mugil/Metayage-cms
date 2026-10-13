<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trademark service codes run 3–5 characters (TMF, TMRN, TMINF).
        Schema::table('jurisdiction_lifecycle_templates', function (Blueprint $table): void {
            $table->string('service_code', 8)->default('*')->change();
        });
        Schema::table('service_transition_rules', function (Blueprint $table): void {
            $table->string('from_service_code', 8)->change();
            $table->string('to_service_code', 8)->change();
        });

        Schema::table('trademark_applications', function (Blueprint $table): void {
            $table->foreignId('mark_document_id')->nullable()->after('mark_type')
                ->constrained('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trademark_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('mark_document_id');
        });
    }
};
