<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_items')) {
            Schema::create('compliance_items', function (Blueprint $table) {
                $table->id();
                $table->string('matter');
                $table->string('type', 30);               // Patent | Trademark | Copyright
                $table->string('jurisdiction', 30);       // USPTO | EPO | WIPO | IPO India | EUIPO
                $table->date('deadline');
                $table->string('action_required');
                $table->string('assignee')->nullable();
                $table->string('status', 20)->default('On Track'); // Critical | At Risk | On Track | Compliant | Resolved
                $table->json('notes')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reminders')) {
            Schema::create('reminders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('category', 20)->default('Deadline'); // Deadline | Meeting | Follow-up | Renewal
                $table->date('due_date');
                $table->string('due_time', 10)->nullable();
                $table->string('scope', 10)->default('self');        // self | team
                $table->boolean('completed')->default(false);
                $table->string('source')->nullable();                // e.g. compliance:3
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('feedback_entries')) {
            Schema::create('feedback_entries', function (Blueprint $table) {
                $table->id();
                $table->string('client_name');
                $table->unsignedTinyInteger('rating');
                $table->text('comment');
                $table->string('category', 30)->default('Overall');
                $table->date('entry_date');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('performance_reviews')) {
            Schema::create('performance_reviews', function (Blueprint $table) {
                $table->id();
                $table->string('employee');
                $table->string('reviewer');
                $table->string('period', 20);
                $table->decimal('rating', 3, 1)->default(0);
                $table->string('status', 20)->default('Not Started'); // Not Started | In Progress | Completed
                $table->json('scores')->nullable();
                $table->text('comments')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('performance_goals')) {
            Schema::create('performance_goals', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('employee');
                $table->string('due_label', 30);
                $table->unsignedTinyInteger('progress')->default(0);
                $table->string('status', 20)->default('On Track');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('performance_feedback360')) {
            Schema::create('performance_feedback360', function (Blueprint $table) {
                $table->id();
                $table->string('from_name');
                $table->string('to_name');
                $table->string('sent_label', 30);
                $table->string('status', 20)->default('Pending'); // Pending | Submitted
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('job_postings')) {
            Schema::create('job_postings', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('dept', 50);
                $table->date('posted_date');
                $table->unsignedInteger('applicants')->default(0);
                $table->string('status', 20)->default('Active'); // Active | Closed
                $table->text('description')->nullable();
                $table->string('employment_type', 20)->default('Full-time');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('job_candidates')) {
            Schema::create('job_candidates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('role');
                $table->string('stage', 20)->default('Applied'); // Applied | Screening | Interview | Offer | Hired
                $table->string('applied_label', 30);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('offboarding_cases')) {
            Schema::create('offboarding_cases', function (Blueprint $table) {
                $table->id();
                $table->string('employee');
                $table->string('dept', 50);
                $table->string('last_day', 30);
                $table->string('exit_type', 20)->default('Resignation'); // Resignation | Retirement | Termination
                $table->string('status', 20)->default('Scheduled');      // Scheduled | In Progress | Completed
                $table->json('checklist');                                // bool[8]
                $table->string('assigned_hr');
                $table->string('completed_label', 30)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('integrations')) {
            Schema::create('integrations', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 30)->unique();
                $table->string('name');
                $table->string('description');
                $table->string('category', 30);
                $table->string('initials', 4);
                $table->string('color', 30);
                $table->boolean('connected')->default(false);
                $table->string('last_sync', 30)->nullable();
                $table->string('sync_freq', 30)->nullable();
                $table->json('config')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('clients', 'portal_enabled')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->boolean('portal_enabled')->default(false);
                $table->timestamp('portal_invited_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('offboarding_cases');
        Schema::dropIfExists('job_candidates');
        Schema::dropIfExists('job_postings');
        Schema::dropIfExists('performance_feedback360');
        Schema::dropIfExists('performance_goals');
        Schema::dropIfExists('performance_reviews');
        Schema::dropIfExists('feedback_entries');
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('compliance_items');
        if (Schema::hasColumn('clients', 'portal_enabled')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn(['portal_enabled', 'portal_invited_at']);
            });
        }
    }
};
