<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A person's named filter sets on a page (e.g. "My overdue FERs" on the Patent Portfolio). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('page', 40);
            $table->string('name', 60);
            $table->json('filters');
            $table->timestamps();
            $table->index(['user_id', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
