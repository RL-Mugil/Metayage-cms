<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form16_documents', function (Blueprint $table) {
            // Matches Zoho's real flow: Upload → Generate → (sign, out of
            // scope for a web app) → Publish. Employees can't see/download a
            // document until it's explicitly published, even once merged.
            if (! Schema::hasColumn('form16_documents', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('form16_documents', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
