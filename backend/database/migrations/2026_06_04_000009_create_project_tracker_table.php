<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_circles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('tracker_circle_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained('tracker_circles')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->unique(['circle_id', 'user_id']);
            $table->timestamps();
        });

        Schema::create('tracker_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained('tracker_circles')->onDelete('cascade');
            $table->string('docket_number')->nullable();
            $table->string('client_name')->nullable();
            $table->string('record_type')->nullable();
            $table->string('pcm')->nullable();
            $table->string('scm')->nullable();
            $table->string('pr')->nullable();
            $table->date('project_start_date')->nullable();
            $table->string('status')->default('Not Started');
            $table->date('delivery_due_date')->nullable();
            $table->string('payment_status')->default('Pending');
            $table->integer('percentage_of_completion')->default(0);
            $table->string('uin')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed the two circles
        DB::table('tracker_circles')->insert([
            ['name' => 'Circle A', 'slug' => 'a', 'description' => 'Circle A — Patent & IP Portfolio', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Circle B', 'slug' => 'b', 'description' => 'Circle B — FER & Project Tracker', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_rows');
        Schema::dropIfExists('tracker_circle_members');
        Schema::dropIfExists('tracker_circles');
    }
};
