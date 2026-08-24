<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes budget/estimate approvals (client_admin approves spend
 * before work proceeds) from technical/draft approvals (inventor and/or
 * client_admin sign off that a draft is ready to file) — see the call notes
 * behind ApprovalController::store()/resolve(). Nullable, no default: rows
 * created before this column existed predate the distinction and are left
 * as legacy/unspecified (resolvable by client_admin only, same as today)
 * rather than guessed at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->string('kind')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
