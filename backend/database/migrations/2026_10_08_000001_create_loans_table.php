<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('loans')) {
            return;
        }

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('principal_amount', 12, 2);
            $table->decimal('monthly_installment', 12, 2);
            $table->date('start_period');
            $table->decimal('remaining_balance', 12, 2);
            $table->string('status')->default('Active'); // Active, Closed
            $table->text('notes')->nullable();
            $table->foreignId('given_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
