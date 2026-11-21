<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IPO Form 1 needs the applicant's address broken into house no. / street / city /
 * state / country / pin code, the applicant category (3B), and each patent agent's
 * IN/PA number and mobile (section 6). All additive; the legacy free-text
 * clients.address stays and is used as a fallback when the structured parts are empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('address_house')->nullable();
            $table->text('address_street')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->text('pincode')->nullable();
            // natural_person | other_than_natural | educational | small_entity | startup | others
            $table->string('applicant_category', 30)->nullable();
        });

        // Country defaults from nationality, which is what every existing row carries.
        DB::table('clients')->whereNull('country')->whereNotNull('nationality')
            ->update(['country' => DB::raw('nationality')]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('patent_agent_no', 30)->nullable();   // IN/PA number
            $table->text('mobile')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['patent_agent_no', 'mobile']);
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['address_house', 'address_street', 'city', 'country', 'pincode', 'applicant_category']);
        });
    }
};
