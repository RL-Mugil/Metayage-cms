<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Registered Patent Agent's signature image (PNG/JPG up to 2 MB, uploaded in
 * HRMS → Employees → Edit), stored on the private disk and placed in the
 * signature block of the IPO forms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('patent_agent_signature_path')->nullable()->after('patent_agent_no');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('patent_agent_signature_path');
        });
    }
};
