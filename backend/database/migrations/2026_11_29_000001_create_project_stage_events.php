<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work-stage log for the case Lifecycle tab (the Project Tracker's stages): each row is a
 * stage started or completed on a date, recorded by hand or logged automatically when a
 * stage moves. project_stages.start_source / end_source say where the current date came
 * from, so automatic logging never overwrites a date someone entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_stage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_stage_id')->nullable()->constrained('project_stages')->nullOnDelete();
            $table->string('stage_name');
            $table->string('event', 16);          // started | completed
            $table->date('event_date');
            $table->string('source', 24);         // manual | tracker | stage_picker | docket | status | bulk
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'event_date']);
        });

        Schema::table('project_stages', function (Blueprint $table) {
            $table->string('start_source', 16)->nullable()->after('actual_start_at');   // manual | auto
            $table->string('end_source', 16)->nullable()->after('actual_end_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_stages', function (Blueprint $table) {
            $table->dropColumn(['start_source', 'end_source']);
        });
        Schema::dropIfExists('project_stage_events');
    }
};
