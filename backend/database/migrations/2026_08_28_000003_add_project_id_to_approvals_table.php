<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approvals had no case/UIN link at all — an approval request (to a client
     * or a colleague) couldn't be tagged to the case it's actually about.
     * Nullable: not every approval is case-specific (e.g. a general/internal
     * request), so tagging a case stays optional.
     */
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('client_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
