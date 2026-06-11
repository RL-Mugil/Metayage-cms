<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('cascade');
            $table->string('file_name');
            $table->string('file_type'); // MIME type
            $table->bigInteger('file_size'); // bytes
            $table->string('category')->default('Internal'); // Invention Disclosure, Draft, Form, Filing, Office Action, Financial, etc.
            $table->string('storage_path'); // MinIO key / S3 path
            $table->integer('current_version')->default(1);
            $table->foreignId('uploaded_by_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('checked_out_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_out_at')->nullable();
            $table->boolean('ocr_enabled')->default(false);
            $table->longText('ocr_content')->nullable(); // Searchable full text
            $table->json('metadata')->nullable();
            $table->string('status')->default('Draft'); // Draft, Under Review, Approved, Signed, Archived
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->onDelete('cascade');
            $table->integer('version_number');
            $table->string('file_name');
            $table->bigInteger('file_size');
            $table->string('storage_path');
            $table->foreignId('uploaded_by_id')->constrained('users')->onDelete('cascade');
            $table->text('version_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
