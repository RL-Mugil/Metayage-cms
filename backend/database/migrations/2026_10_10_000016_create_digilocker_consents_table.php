<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks the server-side half of DigiLocker's three-legged OAuth consent
     * flow — a state_token correlates the browser redirect back in
     * DigiLockerConsentController::callback() with the employee/document type
     * that started it, since that round-trip spans two separate requests.
     */
    public function up(): void
    {
        Schema::create('digilocker_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('state_token', 64)->unique();
            $table->string('document_type'); // AADHAAR | PAN
            $table->string('status')->default('Pending'); // Pending | Completed | Failed
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digilocker_consents');
    }
};
