<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot table letting a document be attached to one or more approval
     * requests — same reasoning as document_task (2026_08_28_000002): a
     * many-to-many so an already-uploaded document can be selected for more
     * than one approval without moving ownership.
     */
    public function up(): void
    {
        Schema::create('approval_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_id')->constrained('approvals')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['approval_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_documents');
    }
};
