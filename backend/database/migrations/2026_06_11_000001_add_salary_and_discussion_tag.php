<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guards make this safe to re-run against drifted schemas.
        if (! Schema::hasColumn('employees', 'salary')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->decimal('salary', 12, 2)->nullable()->after('bank_ifsc_code');
            });
        }

        if (! Schema::hasColumn('discussion_threads', 'tag')) {
            Schema::table('discussion_threads', function (Blueprint $table) {
                $table->string('tag')->default('General')->after('title');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'salary')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('salary');
            });
        }

        if (Schema::hasColumn('discussion_threads', 'tag')) {
            Schema::table('discussion_threads', function (Blueprint $table) {
                $table->dropColumn('tag');
            });
        }
    }
};
