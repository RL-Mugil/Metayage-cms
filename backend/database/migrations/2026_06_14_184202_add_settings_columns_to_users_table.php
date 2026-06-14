<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'timezone')) {
                $table->string('timezone')->default('Asia/Kolkata')->after('remember_token');
            }
            if (!Schema::hasColumn('users', 'language')) {
                $table->string('language')->default('English')->after('timezone');
            }
            if (!Schema::hasColumn('users', 'notification_prefs')) {
                $table->json('notification_prefs')->nullable()->after('language');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'timezone')) {
                $table->dropColumn('timezone');
            }
            if (Schema::hasColumn('users', 'language')) {
                $table->dropColumn('language');
            }
            if (Schema::hasColumn('users', 'notification_prefs')) {
                $table->dropColumn('notification_prefs');
            }
        });
    }
};
