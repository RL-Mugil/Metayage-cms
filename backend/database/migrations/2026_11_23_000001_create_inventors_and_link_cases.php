<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventors become firm-wide records that need no login: staff add them on the case
 * form with every IPO Form 1 particular, and pick the same person again on later
 * cases. Portal access is optional (inventors.user_id). project_inventors now links
 * cases to inventor records in filing order, instead of to inventor-role users, and
 * the one-day-old inventor_profiles table folds into inventors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->nullable()->constrained('firms')->nullOnDelete();
            $table->string('full_name');                       // family name first, as the IPO asks
            $table->text('gender')->nullable();
            $table->text('age')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('country_of_residence', 100)->nullable();
            $table->text('address_house')->nullable();
            $table->text('address_street')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->text('pincode')->nullable();
            $table->string('email')->nullable();
            $table->text('phone')->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['firm_id', 'full_name']);
        });

        Schema::table('project_inventors', function (Blueprint $table) {
            $table->foreignId('inventor_id')->nullable()->after('project_id')->constrained('inventors')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence')->default(0)->after('inventor_id');
        });

        // Carry every existing portal inventor over as an inventor record linked to
        // their login, keeping any particulars saved in inventor_profiles.
        $profiles = Schema::hasTable('inventor_profiles')
            ? DB::table('inventor_profiles')->get()->keyBy('user_id')
            : collect();
        $created = [];
        foreach (DB::table('project_inventors')->orderBy('id')->get() as $row) {
            if (! isset($created[$row->user_id])) {
                $user = DB::table('users')->where('id', $row->user_id)->first();
                $profile = $profiles->get($row->user_id);
                $created[$row->user_id] = DB::table('inventors')->insertGetId([
                    'firm_id'              => DB::table('projects')->where('id', $row->project_id)->value('firm_id'),
                    'full_name'            => $profile->full_name ?? $user->name ?? 'Inventor',
                    'gender'               => $profile->gender ?? null,
                    'age'                  => $profile->age ?? null,
                    'nationality'          => $profile->nationality ?? null,
                    'country_of_residence' => $profile->country_of_residence ?? null,
                    'address_house'        => $profile->address_house ?? null,
                    'address_street'       => $profile->address_street ?? null,
                    'city'                 => $profile->city ?? null,
                    'state'                => $profile->state ?? null,
                    'country'              => $profile->country ?? null,
                    'pincode'              => $profile->pincode ?? null,
                    'email'                => $user->email ?? null,
                    'user_id'              => $user ? $row->user_id : null,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            }
            DB::table('project_inventors')->where('id', $row->id)->update(['inventor_id' => $created[$row->user_id]]);
        }

        Schema::table('project_inventors', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->unique(['project_id', 'inventor_id']);
        });

        Schema::dropIfExists('inventor_profiles');
    }

    public function down(): void
    {
        Schema::create('inventor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('full_name')->nullable();
            $table->text('gender')->nullable();
            $table->text('age')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('country_of_residence', 100)->nullable();
            $table->text('address_house')->nullable();
            $table->text('address_street')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->text('pincode')->nullable();
            $table->timestamps();
        });

        Schema::table('project_inventors', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('project_id')->constrained('users')->cascadeOnDelete();
        });

        // Only inventors with a portal login can be represented in the old shape.
        foreach (DB::table('project_inventors')->get() as $row) {
            $userId = DB::table('inventors')->where('id', $row->inventor_id)->value('user_id');
            if ($userId) {
                DB::table('project_inventors')->where('id', $row->id)->update(['user_id' => $userId]);
            } else {
                DB::table('project_inventors')->where('id', $row->id)->delete();
            }
        }

        Schema::table('project_inventors', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'inventor_id']);
            $table->dropConstrainedForeignId('inventor_id');
            $table->dropColumn('sequence');
            $table->unique(['project_id', 'user_id']);
        });

        Schema::dropIfExists('inventors');
    }
};
