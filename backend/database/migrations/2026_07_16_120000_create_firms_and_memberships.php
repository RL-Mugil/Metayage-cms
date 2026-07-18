<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('slug')->unique();
            $table->string('status', 20)->default('Active')->index();
            $table->char('country_code', 2)->default('IN');
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->char('currency', 3)->default('INR');
            $table->string('data_region', 32)->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('firm_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            $table->string('status', 20)->default('Active')->index();
            $table->boolean('is_default')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['firm_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Nullable during the compatibility phase. A later migration will
            // enforce it after every user-creation path is tenant-aware.
            $table->foreignId('current_firm_id')->nullable()
                ->after('id')
                ->constrained('firms')
                ->nullOnDelete();
        });

        $now = now();
        $firmId = DB::table('firms')->insertGetId([
            'name' => (string) config('app.name', 'MyIPStrategy'),
            'slug' => 'legacy-firm',
            'status' => 'Active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
            'settings' => json_encode(['bootstrap_source' => 'single_firm_backfill']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('users')->orderBy('id')->chunkById(500, function ($users) use ($firmId, $now) {
            $memberships = $users->map(fn ($user) => [
                'firm_id' => $firmId,
                'user_id' => $user->id,
                'role' => $user->role ?: 'client',
                'status' => $user->status === 'Active' ? 'Active' : 'Inactive',
                'is_default' => true,
                'joined_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if ($memberships !== []) {
                DB::table('firm_user')->insertOrIgnore($memberships);
            }
        });

        DB::table('users')->update(['current_firm_id' => $firmId]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_firm_id');
        });

        Schema::dropIfExists('firm_user');
        Schema::dropIfExists('firms');
    }
};
