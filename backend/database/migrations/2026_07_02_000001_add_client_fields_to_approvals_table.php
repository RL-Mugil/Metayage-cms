<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            if (! Schema::hasColumn('approvals', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('approver_id')
                    ->constrained('clients')->nullOnDelete();
            }
            if (! Schema::hasColumn('approvals', 'type')) {
                $table->string('type')->default('client')->after('client_id');
            }
            if (! Schema::hasColumn('approvals', 'title')) {
                $table->string('title')->nullable()->after('type');
            }
            if (! Schema::hasColumn('approvals', 'description')) {
                $table->text('description')->nullable()->after('title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['type', 'title', 'description']);
        });
    }
};
