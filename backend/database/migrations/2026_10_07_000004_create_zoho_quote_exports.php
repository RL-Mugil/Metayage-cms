<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('zoho_quote_exports', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 80);
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_id', 80);
            $table->string('reference', 100);
            $table->string('estimate_id', 80)->nullable();
            $table->string('state', 20);
            $table->text('payload');
            $table->timestamps();
            $table->unique(['scope', 'source_type', 'source_id']);
            $table->unique(['scope', 'reference']);
            $table->unique(['scope', 'estimate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_quote_exports');
    }
};
