<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('original_docket')->nullable()->after('docket_number');
        });

        Schema::create('project_elevations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('predecessor_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('from_service_code', 10);
            $table->string('to_service_code', 10);
            $table->string('from_docket', 100);
            $table->string('to_docket', 100);
            $table->timestamp('elevated_at');
            $table->foreignId('elevated_by_id')->constrained('users');
            $table->text('note')->nullable();
            $table->boolean('is_retroactive_link')->default(false);
            $table->timestamps();

            $table->index('project_id');
            $table->index('predecessor_project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_elevations');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('original_docket');
        });
    }
};
