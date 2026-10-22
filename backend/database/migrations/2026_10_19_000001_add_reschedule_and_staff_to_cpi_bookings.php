<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cpi_bookings', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->default(0);              // calendar SEQUENCE: lets an updated invite replace the old event
            $table->unsignedInteger('reschedule_count')->default(0);
            $table->timestamp('rescheduled_from_utc')->nullable();
            $table->string('last_rescheduled_by', 12)->nullable();        // visitor | admin
            $table->text('manage_token_enc')->nullable();                  // reversible copy so later emails can carry the same manage link
        });

        Schema::create('cpi_booking_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('cpi_bookings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['booking_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cpi_booking_staff');
        Schema::table('cpi_bookings', function (Blueprint $table) {
            $table->dropColumn(['sequence', 'reschedule_count', 'rescheduled_from_utc', 'last_rescheduled_by', 'manage_token_enc']);
        });
    }
};
