<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discussion_threads', function (Blueprint $table) {
            if (! Schema::hasColumn('discussion_threads', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('project_id')
                    ->constrained('clients')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('discussion_threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
