<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracker_rows', function (Blueprint $table) {
            $table->string('status')->nullable()->default(null)->change();
            $table->string('payment_status')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tracker_rows', function (Blueprint $table) {
            $table->string('status')->nullable(false)->default('Not Started')->change();
            $table->string('payment_status')->nullable(false)->default('Pending')->change();
        });
    }
};
