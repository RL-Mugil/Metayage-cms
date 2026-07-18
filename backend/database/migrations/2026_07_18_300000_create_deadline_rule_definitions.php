<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deadline_rule_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('rule_code', 96);
            $table->string('version', 32);
            $table->string('jurisdiction', 8);
            $table->string('right_type', 32)->default('Patent');
            $table->string('event_type', 64);
            $table->string('title');
            $table->text('legal_basis');
            $table->string('anchor_field', 32)->default('event_date');
            $table->string('offset_unit', 16);
            $table->unsignedSmallInteger('offset_value');
            $table->unsignedSmallInteger('outer_offset_value')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 24)->default('Draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['rule_code', 'version']);
            $table->index(['jurisdiction', 'event_type', 'status', 'effective_from'], 'deadline_rule_lookup');
        });

        Schema::table('docket_deadlines', function (Blueprint $table): void {
            $table->foreignId('deadline_rule_definition_id')->nullable()
                ->constrained('deadline_rule_definitions')->nullOnDelete();
            $table->string('risk_level', 16)->default('High');
            $table->index(['status', 'review_status', 'risk_level']);
        });

        $rules = [
            ['IN.PAT.PROVISIONAL_FILED.01', 'provisional_filed', 'Complete Specification Due', 'S.9(1)', 'months', 12, null],
            ['IN.PAT.FER_RECEIVED.01', 'fer_received', 'FER/OA Response Due', 'Rule 24B(5) and Rule 24B(6)', 'months', 6, 9],
            ['IN.PAT.HEARING_HELD.01', 'hearing_held', 'Written Submissions Due', 'Rule 28(7)', 'days', 15, null],
            ['IN.PAT.REFUSED.01', 'refused', 'Review Petition Due', 'S.77(1)(f)', 'months', 1, null],
            ['IN.PAT.REFUSED.02', 'refused', 'Appeal Due', 'S.117A', 'months', 3, null],
            ['IN.PAT.RENEWAL_MISSED.01', 'renewal_missed', 'Restoration Window Closes', 'S.60 / Form 15', 'months', 18, null],
            ['IN.PAT.OPPOSITION_NOTICE.01', 'opposition_notice', 'Reply to Pre-Grant Opposition Due', 'Rule 55(4)', 'months', 2, null],
        ];
        foreach ($rules as [$code, $event, $title, $basis, $unit, $offset, $outer]) {
            DB::table('deadline_rule_definitions')->insert([
                'rule_code' => $code, 'version' => '2026.1-candidate', 'jurisdiction' => 'IN',
                'event_type' => $event, 'title' => $title, 'legal_basis' => $basis,
                'offset_unit' => $unit, 'offset_value' => $offset, 'outer_offset_value' => $outer,
                'effective_from' => '2024-03-15', 'status' => 'Draft',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('docket_deadlines', function (Blueprint $table): void {
            $table->dropIndex(['status', 'review_status', 'risk_level']);
            $table->dropConstrainedForeignId('deadline_rule_definition_id');
            $table->dropColumn('risk_level');
        });
        Schema::dropIfExists('deadline_rule_definitions');
    }
};
