<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client reminder configuration, following the same JSON-column pattern
 * as Client.communication_preference: reminder_cadence_override lets a
 * client manager add extra day-thresholds on top of the firm-default cadence
 * (e.g. Niramai's extra 1-month-before renewal reminder); payment_clearance_
 * pattern records a manually-observed "usually pays N days before due date"
 * so early escalation can fire if that pattern is breached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->json('reminder_cadence_override')->nullable()->after('communication_preference');
            $table->json('payment_clearance_pattern')->nullable()->after('reminder_cadence_override');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['reminder_cadence_override', 'payment_clearance_pattern']);
        });
    }
};
