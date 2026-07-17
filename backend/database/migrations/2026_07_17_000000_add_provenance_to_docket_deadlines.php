<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('docket_deadlines', function (Blueprint $table): void {
            $table->string('source_type', 24)->default('Manual')->after('legal_basis');
            $table->string('rule_code', 96)->nullable()->after('source_type');
            $table->string('rule_version', 32)->nullable()->after('rule_code');
            $table->json('calculation_trace')->nullable()->after('rule_version');
            $table->string('review_status', 24)->default('Unreviewed')->after('calculation_trace');
            $table->foreignId('reviewed_by')->nullable()->after('review_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->index(['source_type', 'review_status']);
            $table->index(['rule_code', 'rule_version']);
        });

        DB::table('docket_deadlines')
            ->whereNotNull('docket_event_id')
            ->update([
                'source_type' => 'System Rule',
                'rule_version' => 'legacy-pre-versioning',
            ]);
    }

    public function down(): void
    {
        Schema::table('docket_deadlines', function (Blueprint $table): void {
            $table->dropIndex(['source_type', 'review_status']);
            $table->dropIndex(['rule_code', 'rule_version']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn([
                'source_type', 'rule_code', 'rule_version', 'calculation_trace',
                'review_status', 'reviewed_at',
            ]);
        });
    }
};
