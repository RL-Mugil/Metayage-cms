<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fields for the IPO-style "Application Details"/"Application Status" case view.
// Most of that view reuses dates PatentApplication already tracks (filing_date,
// publication_date, rfe_filed_date, grant_date) — these four are the ones with
// no existing equivalent anywhere in the schema.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patent_applications', function (Blueprint $table) {
            $table->string('application_type')->nullable()->after('jurisdiction'); // Ordinary / Convention / PCT National Phase / Divisional / Patent of Addition
            $table->date('fer_reply_date')->nullable()->after('grant_date');
            $table->date('certificate_issue_date')->nullable()->after('fer_reply_date');
            $table->date('post_grant_journal_date')->nullable()->after('certificate_issue_date');
        });
    }

    public function down(): void
    {
        Schema::table('patent_applications', function (Blueprint $table) {
            $table->dropColumn(['application_type', 'fer_reply_date', 'certificate_issue_date', 'post_grant_journal_date']);
        });
    }
};
