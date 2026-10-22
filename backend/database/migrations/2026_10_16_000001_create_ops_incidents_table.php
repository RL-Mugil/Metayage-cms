<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per distinct operational problem (not per occurrence). Errors from the app and from the server monitor
 * collect here quietly; a single report is emailed each morning, and a recovery email is sent only when a problem
 * that was already reported clears up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 191)->unique();   // identifies "the same problem" across repeats
            $table->string('source', 12)->default('app');   // app | monitor
            $table->string('scope', 100)->nullable()->index(); // artisan command the error came from, so its next success can resolve it
            $table->string('title', 250);
            $table->text('meaning');                        // plain English: what happened
            $table->text('impact');                         // plain English: what it affects
            $table->text('action');                         // plain English: what to do
            $table->text('technical')->nullable();          // one short technical line for the developer
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('quiet_minutes')->default(360); // app incidents resolve after this long without a repeat (0 = only explicit)
            $table->string('status', 10)->default('open')->index(); // open | resolved
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('reported_at')->nullable();           // included in a morning report while open
            $table->timestamp('recovery_mailed_at')->nullable();    // recovery email sent (or deliberately not needed)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_incidents');
    }
};
