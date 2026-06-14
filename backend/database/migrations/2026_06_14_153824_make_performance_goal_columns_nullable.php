<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') return; // SQLite test env; controller always sets these

        Schema::table('performance_goals', function (Blueprint $table) {
            $table->string('employee')->nullable()->change();
            $table->string('due_label', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') return;

        Schema::table('performance_goals', function (Blueprint $table) {
            $table->string('employee')->nullable(false)->default('')->change();
            $table->string('due_label', 30)->nullable(false)->default('TBD')->change();
        });
    }
};
