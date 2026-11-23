<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US maintenance fees (3.5 / 7.5 / 11.5 years from grant) live in renewal_schedules as
 * renewal_year 4 / 8 / 12, with a label and the payment window: it opens 6 months
 * before the due date and a 6-month grace period with surcharge follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('renewal_schedules', function (Blueprint $table) {
            $table->string('label', 60)->nullable()->after('renewal_year');
            $table->date('window_opens_on')->nullable()->after('due_date');
            $table->date('grace_ends_on')->nullable()->after('window_opens_on');
        });
    }

    public function down(): void
    {
        Schema::table('renewal_schedules', function (Blueprint $table) {
            $table->dropColumn(['label', 'window_opens_on', 'grace_ends_on']);
        });
    }
};
