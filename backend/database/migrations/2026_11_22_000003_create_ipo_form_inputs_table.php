<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-case input the paralegal gives when preparing an IPO form (application type,
 * priority / PCT / divisional / addition particulars, co-applicants, agents,
 * declarations). Keyed by form type so Forms 3, 5, 9, 18 can reuse the same input.
 * `data` is encrypted (it can hold co-applicant addresses, emails and phone numbers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ipo_form_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('form_type', 20);
            $table->text('data');
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'form_type']);
        });

        // Section 7 (address for service in India) defaults to the firm's letterhead;
        // contact numbers and email are entered in Settings → IPO Forms.
        $rows = DB::table('system_settings')->pluck('value', 'key');
        $now = now();
        $defaults = [
            'ipo_service_name'    => $rows['payroll_company_name'] ?? 'Metayage Private Limited',
            'ipo_service_address' => $rows['payroll_company_address'] ?? '',
            'ipo_service_phone'   => '',
            'ipo_service_mobile'  => '',
            'ipo_service_fax'     => '',
            'ipo_service_email'   => '',
        ];
        foreach ($defaults as $key => $value) {
            if (! isset($rows[$key])) {
                DB::table('system_settings')->insert(['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ipo_form_inputs');
        DB::table('system_settings')->where('key', 'like', 'ipo_service_%')->delete();
    }
};
