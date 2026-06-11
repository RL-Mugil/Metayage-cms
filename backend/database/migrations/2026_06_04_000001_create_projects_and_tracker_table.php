<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_code')->unique(); // PRJ-YYYY-XXXXX
            $table->string('matter_reference')->nullable();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('project_type');
            $table->string('project_name');
            $table->string('invention_title')->nullable();
            $table->string('technology_field')->nullable();
            $table->foreignId('parent_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->date('priority_date')->nullable();
            $table->foreignId('assigned_partner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('assigned_team')->nullable(); // JSON list of user IDs
            $table->date('start_date')->nullable();
            $table->date('target_filing_date')->nullable();
            $table->date('hard_deadline')->nullable();
            $table->decimal('estimated_hours', 8, 2)->default(0.00);
            $table->decimal('budget', 15, 2)->default(0.00);
            $table->string('fee_arrangement')->default('Fixed Fee');
            $table->string('status')->default('Open');
            $table->string('urgency')->default('Normal');
            $table->string('confidentiality_level')->default('Standard');
            $table->json('tags')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('project_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->string('stage_name');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('duration_days')->default(1);
            $table->date('due_date')->nullable();
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('checklist')->nullable(); // list of subtasks
            $table->json('gate_criteria')->nullable(); // rules to move past this stage
            $table->integer('sequence_order')->default(0);
            $table->string('status')->default('Pending'); // Pending, In Progress, Completed, Escalated
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_stages');
        Schema::dropIfExists('projects');
    }
};
