<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'clients',
        'projects',
        'patent_applications',
        'docket_events',
    ];

    public function up(): void
    {
        $firmId = DB::table('firms')->where('slug', 'legacy-firm')->value('id');
        if ($firmId === null) {
            throw new RuntimeException('The MYPL compatibility firm must exist before ownership backfill.');
        }

        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('firm_id')->nullable()->after('id')
                    ->constrained('firms')->restrictOnDelete();
                $table->index('firm_id');
            });

            DB::table($tableName)->whereNull('firm_id')->update(['firm_id' => $firmId]);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex(['firm_id']);
            });

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('firm_id');
            });
        }
    }
};
