<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_enquiries', function (Blueprint $table) {
            $table->string('type', 20)->default('enquiry')->after('source'); // enquiry|quiz|booking
            $table->json('payload')->nullable()->after('message');
        });

        Schema::create('cpi_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->string('slug', 120)->unique();
            $table->string('excerpt', 320)->nullable();
            $table->longText('body_html');
            $table->string('meta_title', 160)->nullable();
            $table->string('meta_description', 200)->nullable();
            $table->string('author', 100)->nullable();
            $table->string('status', 12)->default('draft')->index(); // draft|published
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cpi_availability_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('weekday'); // 0 = Sunday .. 6 = Saturday
            $table->string('start_time', 5);        // HH:MM in the booking timezone
            $table->string('end_time', 5);
            $table->timestamps();
        });

        Schema::create('cpi_blackouts', function (Blueprint $table) {
            $table->id();
            $table->date('day')->unique();
            $table->string('note', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('cpi_bookings', function (Blueprint $table) {
            $table->id();
            $table->timestamp('start_utc');
            $table->timestamp('end_utc');
            $table->string('name', 100);
            $table->string('email', 150);
            $table->string('organization', 150)->nullable();
            $table->text('topic')->nullable();
            $table->string('visitor_timezone', 64)->nullable();
            $table->string('status', 12)->default('confirmed'); // confirmed|cancelled
            $table->string('manage_token_hash', 64);
            $table->timestamps();
            $table->index('start_utc');
        });

        // A time can be taken once; cancelled bookings free the slot again.
        DB::statement("CREATE UNIQUE INDEX cpi_bookings_start_confirmed_unique ON cpi_bookings (start_utc) WHERE status = 'confirmed'");

        // Sensible default: Monday to Friday, 10:00-17:00 in the booking timezone. Editable in the dashboard.
        $now = now();
        foreach ([1, 2, 3, 4, 5] as $d) {
            DB::table('cpi_availability_rules')->insert(['weekday' => $d, 'start_time' => '10:00', 'end_time' => '17:00', 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cpi_bookings');
        Schema::dropIfExists('cpi_blackouts');
        Schema::dropIfExists('cpi_availability_rules');
        Schema::dropIfExists('cpi_articles');
        Schema::table('site_enquiries', function (Blueprint $table) {
            $table->dropColumn(['type', 'payload']);
        });
    }
};
