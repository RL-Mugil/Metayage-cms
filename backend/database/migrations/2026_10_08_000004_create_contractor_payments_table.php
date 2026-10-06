<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contractor_payments')) {
            return;
        }

        Schema::create('contractor_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number');
            $table->date('invoice_date');
            $table->decimal('amount', 12, 2);
            $table->decimal('tds_deducted', 12, 2)->default(0);
            $table->decimal('net_paid', 12, 2);
            $table->string('status')->default('Pending'); // Pending, Paid
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['contractor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_payments');
    }
};
