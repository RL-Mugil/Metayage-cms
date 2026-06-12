<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'docket_number')) {
                $table->string('docket_number')->nullable()->after('matter_reference');
            }
            if (! Schema::hasColumn('projects', 'application_number')) {
                $table->string('application_number')->nullable()->after('docket_number');
            }
            if (! Schema::hasColumn('projects', 'patent_office_code')) {
                $table->string('patent_office_code', 20)->nullable()->after('application_number');
            }
            if (! Schema::hasColumn('projects', 'service_code')) {
                $table->string('service_code', 50)->nullable()->after('patent_office_code');
            }
            if (! Schema::hasColumn('projects', 'case_type')) {
                $table->string('case_type', 100)->nullable()->after('service_code');
            }
            if (! Schema::hasColumn('projects', 'filing_date')) {
                $table->date('filing_date')->nullable()->after('case_type');
            }
            if (! Schema::hasColumn('projects', 'secondary_manager_id')) {
                $table->foreignId('secondary_manager_id')->nullable()
                    ->constrained('users')->nullOnDelete()
                    ->after('assigned_manager_id');
            }
            if (! Schema::hasColumn('projects', 'patent_engineer_id')) {
                $table->foreignId('patent_engineer_id')->nullable()
                    ->constrained('users')->nullOnDelete()
                    ->after('secondary_manager_id');
            }
            if (! Schema::hasColumn('projects', 'notes')) {
                $table->text('notes')->nullable()->after('tags');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $colsToCheck = [
                'patent_engineer_id', 'secondary_manager_id',
                'filing_date', 'case_type', 'service_code',
                'patent_office_code', 'application_number', 'docket_number', 'notes',
            ];
            $fks = ['patent_engineer_id', 'secondary_manager_id'];
            foreach ($fks as $fk) {
                if (Schema::hasColumn('projects', $fk)) {
                    $table->dropForeign([$fk]);
                }
            }
            $existing = array_filter($colsToCheck, fn ($c) => Schema::hasColumn('projects', $c));
            if ($existing) {
                $table->dropColumn(array_values($existing));
            }
        });
    }
};
