<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('form16_documents')) {
            return;
        }

        Schema::create('form16_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('financial_year');
            $table->string('part_a_path')->nullable(); // TRACES-issued PDF, uploaded via ZIP + PAN match
            $table->string('part_b_path')->nullable(); // our own dompdf-generated tax computation
            $table->string('merged_path')->nullable(); // FPDI merge of both, served to the employee
            $table->boolean('pan_matched')->default(false);
            $table->string('status')->default('PartBOnly'); // PartBOnly, Merged, Failed
            $table->timestamps();

            $table->unique(['employee_id', 'financial_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form16_documents');
    }
};
