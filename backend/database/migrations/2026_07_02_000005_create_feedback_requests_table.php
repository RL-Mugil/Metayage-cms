<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('docket_number')->nullable();
            $table->string('subject')->nullable();
            $table->foreignId('requested_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('Pending'); // Pending, Completed
            $table->unsignedTinyInteger('rating')->nullable(); // 1–5, set by client_admin
            $table->text('comment')->nullable();
            $table->foreignId('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_requests');
    }
};
