<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challans', function (Blueprint $table) {
            $table->id();
            $table->string('bsr_code', 7);
            $table->date('payment_date');
            $table->string('serial_number', 10);
            $table->decimal('amount', 12, 2);
            $table->decimal('interest_amount', 12, 2)->default(0);
            $table->decimal('penalty_amount', 12, 2)->default(0);
            $table->unsignedSmallInteger('financial_year');
            $table->unsignedTinyInteger('quarter')->nullable();
            $table->string('status')->default('Unassociated'); // Unassociated | PartiallyAssociated | Associated
            $table->foreignId('recorded_by_id')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['bsr_code', 'payment_date', 'serial_number']);
            $table->index(['financial_year', 'quarter']);
            $table->index('status');
        });

        Schema::create('challan_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tds_liability_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->foreignId('associated_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['challan_id', 'tds_liability_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challan_allocations');
        Schema::dropIfExists('challans');
    }
};
