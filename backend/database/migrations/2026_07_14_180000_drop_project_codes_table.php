<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('project_codes');
    }

    public function down(): void
    {
        Schema::create('project_codes', function ($table) {
            $table->id();
            $table->string('type');
            $table->string('code');
            $table->string('description');
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamps();
        });
    }
};
