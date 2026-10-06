<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_document_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 80);
            $table->unsignedBigInteger('document_id');
            $table->foreignId('signed_by_id')->constrained('users')->restrictOnDelete();
            $table->string('signer_role', 40);
            $table->string('document_fingerprint', 64);
            $table->string('signature', 128);
            $table->string('verification_code', 32)->unique();
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->unique(['document_type', 'document_id']);
            $table->index(['document_type', 'document_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_document_signatures');
    }
};
