<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('ip_notifications', 'action_url')) {
                $table->string('action_url')->nullable()->after('meta');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ip_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('ip_notifications', 'action_url')) {
                $table->dropColumn('action_url');
            }
        });
    }
};
