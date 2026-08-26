<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// No inventor identity model exists today (PatentInvoiceIn.first_inventor_name is an
// unrelated GST-invoice string field). This pivot is the new identity link: which
// `inventor`-role Users are inventor-of-record on which Projects. An inventor can span
// multiple clients' cases (e.g. an outside professor), so this is keyed by user_id, not
// by any single client — unlike every other portal role in this app.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_inventors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_inventors');
    }
};
