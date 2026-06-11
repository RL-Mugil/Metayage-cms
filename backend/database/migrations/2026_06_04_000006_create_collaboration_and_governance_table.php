<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussion_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            $table->string('title');
            $table->boolean('is_private')->default(false); // Team-only vs client-visible
            $table->string('status')->default('Open'); // Open, Resolved
            $table->timestamps();
        });

        Schema::create('discussion_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('discussion_threads')->onDelete('cascade');
            $table->foreignId('author_id')->constrained('users')->onDelete('cascade');
            $table->text('content');
            $table->json('attachments')->nullable();
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('approver_id')->constrained('users')->onDelete('cascade');
            $table->string('subject_type'); // e.g. Document, Invoice, LeaveRequest, Quotation
            $table->unsignedBigInteger('subject_id');
            $table->string('status')->default('Pending'); // Pending, Approved, Rejected, Clarification
            $table->text('comments')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // e.g. create, update, delete, login, export
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable(); // Changes list or payload
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->text('message');
            $table->string('channel')->default('in-app'); // in-app, email, sms
            $table->boolean('is_read')->default(false);
            $table->string('action_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('discussion_messages');
        Schema::dropIfExists('discussion_threads');
    }
};
