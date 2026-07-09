<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_codes', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // 'office' | 'service'
            $table->string('code', 50);
            $table->string('description', 255);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['type', 'code']); // enforced uppercase on save
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_codes');
    }
};
