<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generated_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('report_type', 50);
            $table->string('report_name');
            $table->string('format', 10);
            $table->json('filters')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->json('snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
