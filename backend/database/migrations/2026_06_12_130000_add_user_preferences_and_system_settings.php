<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone')->default('Asia/Kolkata')->after('role');
            $table->string('language')->default('English')->after('timezone');
            $table->json('notification_prefs')->nullable()->after('language');
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Seed default system settings
        \Illuminate\Support\Facades\DB::table('system_settings')->insert([
            ['key' => 'company_name',    'value' => 'My IP Strategy',  'created_at' => now(), 'updated_at' => now()],
            ['key' => 'currency',        'value' => 'INR',              'created_at' => now(), 'updated_at' => now()],
            ['key' => 'fiscal_month',    'value' => 'April',            'created_at' => now(), 'updated_at' => now()],
            ['key' => 'max_upload_mb',   'value' => '50',               'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'language', 'notification_prefs']);
        });

        Schema::dropIfExists('system_settings');
    }
};
