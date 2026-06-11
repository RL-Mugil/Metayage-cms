<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_code')->nullable(); // TSK-YYYY-XXXXX
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority')->default('Normal'); // Low, Normal, High, Critical
            $table->dateTime('due_date')->nullable();
            $table->decimal('estimated_hours', 8, 2)->default(0.00);
            $table->decimal('actual_hours', 8, 2)->default(0.00);
            $table->string('status')->default('Not Started'); // Not Started, In Progress, Awaiting Review, Completed, Cancelled
            $table->json('dependencies')->nullable(); // blocker task ids
            $table->json('tags')->nullable();
            $table->boolean('recurring')->default(false);
            $table->string('recurrence_pattern')->nullable();
            $table->boolean('billable')->default(true);
            $table->timestamps();
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->decimal('duration_hours', 5, 2);
            $table->date('entry_date');
            $table->text('description')->nullable();
            $table->boolean('billable')->default(true);
            $table->string('status')->default('Draft'); // Draft, Submitted, Approved, Invoiced
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('tasks');
    }
};
