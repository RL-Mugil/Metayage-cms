<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot table letting a document be attached to one or more tasks — a
     * many-to-many, not a single task_id column on documents, so the same
     * already-uploaded document can be "selected from existing" for more than
     * one task without moving it away from wherever it's already attached.
     */
    public function up(): void
    {
        Schema::create('document_task', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['task_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_task');
    }
};
