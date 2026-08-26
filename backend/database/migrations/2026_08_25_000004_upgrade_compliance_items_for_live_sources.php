<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_items', function (Blueprint $table): void {
            $table->foreignId('firm_id')->nullable()->after('id')->constrained('firms')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->after('matter')->constrained('clients')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->after('client_id')->constrained('projects')->nullOnDelete();
            $table->foreignId('patent_application_id')->nullable()->after('project_id')->constrained('patent_applications')->nullOnDelete();
            $table->string('source_type', 40)->default('manual')->after('patent_application_id');
            $table->string('source_key', 120)->nullable()->after('source_type');
            $table->json('source_metadata')->nullable()->after('source_key');
            $table->index(['firm_id', 'deadline'], 'compliance_firm_deadline_idx');
            $table->unique(['firm_id', 'source_key'], 'compliance_firm_source_unique');
        });

        $firmId = DB::table('firms')->where('slug', 'legacy-firm')->value('id')
            ?? DB::table('firms')->where('status', 'Active')->orderBy('id')->value('id');

        if ($firmId !== null) {
            DB::table('compliance_items')->whereNull('firm_id')->update(['firm_id' => $firmId]);
        }
    }

    public function down(): void
    {
        Schema::table('compliance_items', function (Blueprint $table): void {
            $table->dropUnique('compliance_firm_source_unique');
            $table->dropIndex('compliance_firm_deadline_idx');
            $table->dropConstrainedForeignId('patent_application_id');
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('client_id');
            $table->dropConstrainedForeignId('firm_id');
            $table->dropColumn(['source_type', 'source_key', 'source_metadata']);
        });
    }
};
