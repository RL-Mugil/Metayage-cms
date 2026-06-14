<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('tasks', 'assigned_by_id')) {
                $table->unsignedBigInteger('assigned_by_id')->nullable()->after('assignee_id');
                $table->foreign('assigned_by_id')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'assigned_by_id')) {
                $table->dropForeign(['assigned_by_id']);
                $table->dropColumn('assigned_by_id');
            }
        });
    }
};
