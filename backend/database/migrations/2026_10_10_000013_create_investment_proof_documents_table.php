<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_proof_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proof_of_investment_id')->constrained()->cascadeOnDelete();
            $table->string('section'); // 80C | 80D | HRA | HomeLoanInterest | Other
            $table->string('storage_path');
            $table->string('original_filename');
            $table->foreignId('uploaded_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['proof_of_investment_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_proof_documents');
    }
};
