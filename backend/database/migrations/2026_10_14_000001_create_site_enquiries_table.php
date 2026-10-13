<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30)->default('myipstrategy')->index();
            $table->string('name', 100);
            $table->string('email', 150);
            $table->string('organization', 150)->nullable();
            $table->string('stage', 60)->nullable();
            $table->string('domain', 80)->nullable();
            $table->text('message');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('mail_status', 20)->default('pending'); // pending|sent|failed
            $table->text('mail_error')->nullable();
            $table->string('status', 20)->default('new')->index(); // new|handled
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_enquiries');
    }
};
