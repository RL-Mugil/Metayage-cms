<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A client's choice on an upcoming renewal fee: renew, let lapse, or ask the firm first. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('renewal_schedule_id')->constrained('renewal_schedules')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('decision', 10);            // renew | lapse | ask
            $table->text('note')->nullable();
            $table->foreignId('decided_by_id')->constrained('users');
            $table->timestamps();
            $table->unique('renewal_schedule_id');      // latest decision wins (updated in place)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_decisions');
    }
};
