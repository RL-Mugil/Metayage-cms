<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cpi_patents', function (Blueprint $table) {
            $table->id();
            $table->string('patent_number', 60)->unique();   // e.g. "WO/2020/136668" or "US 11,000,000 B2"
            $table->string('slug', 120)->unique();
            $table->string('title', 220);
            $table->string('domain', 80)->index();
            $table->string('country', 20)->default('Others')->index(); // India | USA | Others
            $table->text('abstract');
            $table->string('image', 150)->nullable();         // /insights/img/<hash>.webp (a figure from the patent)
            $table->string('image_alt', 200)->nullable();
            $table->string('status', 12)->default('draft')->index(); // draft | published
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cpi_patents');
    }
};
