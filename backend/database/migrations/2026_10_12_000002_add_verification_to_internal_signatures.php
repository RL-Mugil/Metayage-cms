<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_document_signatures', function (Blueprint $table) {
            $table->boolean('verified')->default(true)->after('signature');
        });
    }

    public function down(): void
    {
        Schema::table('internal_document_signatures', function (Blueprint $table) {
            $table->dropColumn('verified');
        });
    }
};
