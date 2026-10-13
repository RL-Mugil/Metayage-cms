<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cpi_articles', function (Blueprint $table) {
            $table->string('cover_image', 150)->nullable()->after('excerpt');
            $table->string('cover_alt', 200)->nullable()->after('cover_image');
        });
    }

    public function down(): void
    {
        Schema::table('cpi_articles', function (Blueprint $table) {
            $table->dropColumn(['cover_image', 'cover_alt']);
        });
    }
};
