<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->string('event_type'); // connect, disconnect, test, webhook
            $table->string('status')->default('ok'); // ok, fail
            $table->string('summary')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['slug', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};
