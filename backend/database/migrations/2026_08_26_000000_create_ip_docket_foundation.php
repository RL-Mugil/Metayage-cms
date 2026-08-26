<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyFirmId = DB::table('firms')->where('slug', 'legacy-firm')->value('id');
        if ($legacyFirmId === null) {
            throw new RuntimeException('The MYPL compatibility firm must exist before docket ownership backfill.');
        }

        Schema::create('ip_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('record_code', 32);
            $table->string('record_type', 32);
            $table->string('jurisdiction', 8)->default('IN');
            $table->string('title');
            $table->string('client_reference')->nullable();
            $table->string('legal_status', 64)->default('Pending');
            $table->date('status_date')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('backup_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('tags')->nullable();
            $table->string('data_quality_status', 24)->default('Verified');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['firm_id', 'record_code']);
            $table->index(['firm_id', 'record_type', 'jurisdiction', 'legal_status'], 'ip_records_worklist_idx');
        });

        Schema::create('trademark_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->restrictOnDelete();
            $table->foreignId('ip_record_id')->unique()->constrained('ip_records')->cascadeOnDelete();
            $table->string('application_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('mark_text')->nullable();
            $table->string('mark_type', 64)->nullable();
            $table->json('nice_classes')->nullable();
            $table->text('goods_services')->nullable();
            $table->string('proprietor_name')->nullable();
            $table->date('filing_date')->nullable();
            $table->date('journal_date')->nullable();
            $table->string('journal_number')->nullable();
            $table->date('registration_date')->nullable();
            $table->date('renewal_due_date')->nullable();
            $table->string('office_status')->nullable();
            $table->date('office_status_date')->nullable();
            $table->timestamps();
            $table->unique(['firm_id', 'application_number']);
            $table->index(['firm_id', 'renewal_due_date']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('ip_record_id')->nullable()->after('client_id')
                ->constrained('ip_records')->nullOnDelete();
            $table->index(['firm_id', 'ip_record_id']);
        });

        Schema::table('patent_applications', function (Blueprint $table): void {
            $table->foreignId('ip_record_id')->nullable()->after('firm_id')
                ->constrained('ip_records')->nullOnDelete();
            $table->unique('ip_record_id');
        });

        foreach (['docket_deadlines', 'deadline_rule_definitions', 'reminders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('firm_id')->nullable()->after('id')->constrained('firms')->restrictOnDelete();
                $table->index('firm_id');
            });
            DB::table($tableName)->whereNull('firm_id')->update(['firm_id' => $legacyFirmId]);
        }

        Schema::table('deadline_rule_definitions', function (Blueprint $table): void {
            $table->dropUnique(['rule_code', 'version']);
            $table->string('source_type', 16)->default('System');
            $table->foreignId('parent_rule_id')->nullable()->constrained('deadline_rule_definitions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['firm_id', 'rule_code', 'version'], 'deadline_rules_firm_code_version_unique');
        });

        Schema::table('docket_events', function (Blueprint $table): void {
            $table->foreignId('ip_record_id')->nullable()->after('project_id')
                ->constrained('ip_records')->nullOnDelete();
        });

        Schema::table('docket_deadlines', function (Blueprint $table): void {
            $table->foreignId('ip_record_id')->nullable()->after('project_id')
                ->constrained('ip_records')->nullOnDelete();
            $table->date('statutory_due_date')->nullable()->after('due_date');
            $table->string('operational_adjustment', 32)->default('none')->after('statutory_due_date');
            $table->string('supersession_status', 24)->default('Current')->after('review_status');
            $table->foreignId('superseded_by_id')->nullable()->constrained('docket_deadlines')->nullOnDelete();
        });

        Schema::table('reminders', function (Blueprint $table): void {
            $table->foreignId('assigned_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('docket_deadline_id')->nullable()->constrained('docket_deadlines')->cascadeOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['firm_id', 'assigned_user_id', 'due_date'], 'reminders_worklist_idx');
        });

        $this->backfillIpRecords();

        Schema::create('reminder_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name')->default('My Daily Docket');
            $table->string('frequency', 16)->default('daily');
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->time('send_time')->default('09:00');
            $table->unsignedSmallInteger('horizon_days')->default(60);
            $table->json('recipients')->nullable();
            $table->json('filters')->nullable();
            $table->json('columns')->nullable();
            $table->json('color_bands')->nullable();
            $table->boolean('send_empty')->default(false);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('critical_alerts_enabled')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['firm_id', 'user_id', 'name']);
        });

        Schema::create('reminder_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('reminder_profile_id')->constrained('reminder_profiles')->cascadeOnDelete();
            $table->string('idempotency_key', 96)->unique();
            $table->string('channel', 16);
            $table->string('recipient');
            $table->string('status', 24)->default('Pending');
            $table->string('payload_hash', 64);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->text('provider_response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_delivery_attempts');
        Schema::dropIfExists('reminder_profiles');
        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropIndex('reminders_worklist_idx');
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn('acknowledged_at');
            $table->dropConstrainedForeignId('docket_deadline_id');
            $table->dropConstrainedForeignId('assigned_user_id');
        });
        Schema::table('docket_deadlines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('superseded_by_id');
            $table->dropConstrainedForeignId('ip_record_id');
            $table->dropColumn(['statutory_due_date', 'operational_adjustment', 'supersession_status']);
        });
        Schema::table('docket_events', fn (Blueprint $table) => $table->dropConstrainedForeignId('ip_record_id'));

        Schema::table('deadline_rule_definitions', function (Blueprint $table): void {
            $table->dropUnique('deadline_rules_firm_code_version_unique');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('parent_rule_id');
            $table->dropColumn('source_type');
            $table->unique(['rule_code', 'version']);
        });

        foreach (array_reverse(['docket_deadlines', 'deadline_rule_definitions', 'reminders']) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex(['firm_id']);
                $table->dropConstrainedForeignId('firm_id');
            });
        }

        Schema::table('patent_applications', function (Blueprint $table): void {
            $table->dropUnique(['ip_record_id']);
            $table->dropConstrainedForeignId('ip_record_id');
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['firm_id', 'ip_record_id']);
            $table->dropConstrainedForeignId('ip_record_id');
        });
        Schema::dropIfExists('trademark_applications');
        Schema::dropIfExists('ip_records');
    }

    private function backfillIpRecords(): void
    {
        $year = (int) now()->format('Y');
        $sequences = [];

        foreach (DB::table('patent_applications')->orderBy('id')->cursor() as $application) {
            $project = DB::table('projects')->where('patent_application_id', $application->id)->orderBy('id')->first();
            $clientId = $application->client_id ?: $project?->client_id;
            if (! $clientId) continue;
            $firmId = (int) $application->firm_id;
            $sequences[$firmId] = ($sequences[$firmId] ?? 0) + 1;
            $recordId = DB::table('ip_records')->insertGetId([
                'firm_id' => $firmId, 'client_id' => $clientId,
                'record_code' => sprintf('IPR-%d-%05d', $year, $sequences[$firmId]), 'record_type' => 'Patent',
                'jurisdiction' => strtoupper((string) ($application->jurisdiction ?: $project?->patent_office_code ?: 'IN')),
                'title' => $application->title ?: $project?->project_name ?: 'Untitled patent record',
                'client_reference' => $project?->matter_reference, 'legal_status' => $application->legal_status ?: 'Pending',
                'status_date' => $application->grant_date ?: $application->publication_date,
                'responsible_user_id' => $project?->assigned_manager_id ?: $project?->patent_engineer_id,
                'backup_user_id' => $project?->secondary_manager_id, 'data_quality_status' => $project ? 'Verified' : 'Review Required',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('patent_applications')->where('id', $application->id)->update(['ip_record_id' => $recordId]);
            DB::table('projects')->where('patent_application_id', $application->id)->update(['ip_record_id' => $recordId]);
            DB::table('docket_events')->where('patent_application_id', $application->id)->update(['ip_record_id' => $recordId]);
            DB::table('docket_deadlines')->where('patent_application_id', $application->id)->update(['ip_record_id' => $recordId]);
        }

        $trademarkProjects = DB::table('projects')->whereNull('ip_record_id')
            ->whereRaw("LOWER(project_type) LIKE '%trademark%'")->orderBy('id')->cursor();
        foreach ($trademarkProjects as $project) {
            $firmId = (int) $project->firm_id;
            $sequences[$firmId] = ($sequences[$firmId] ?? 0) + 1;
            $recordId = DB::table('ip_records')->insertGetId([
                'firm_id' => $firmId, 'client_id' => $project->client_id,
                'record_code' => sprintf('IPR-%d-%05d', $year, $sequences[$firmId]), 'record_type' => 'Trademark',
                'jurisdiction' => strtoupper((string) ($project->patent_office_code ?: 'IN')),
                'title' => $project->project_name, 'client_reference' => $project->matter_reference,
                'legal_status' => $project->status ?: 'Pending', 'status_date' => $project->filing_date,
                'responsible_user_id' => $project->assigned_manager_id, 'backup_user_id' => $project->secondary_manager_id,
                'data_quality_status' => 'Review Required', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('trademark_applications')->insert([
                'firm_id' => $firmId, 'ip_record_id' => $recordId, 'application_number' => $project->application_number,
                'mark_text' => $project->project_name, 'filing_date' => $project->filing_date,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('projects')->where('id', $project->id)->update(['ip_record_id' => $recordId]);
        }
    }
};
