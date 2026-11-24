<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payslip publishing: every payslip an employee sees is signed by the payroll
 * signatory (name + designation recorded on the signature itself, so later
 * settings changes never rewrite an issued payslip); one payslip per employee
 * per run; signatory defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_document_signatures', function (Blueprint $table) {
            if (! Schema::hasColumn('internal_document_signatures', 'signer_name')) {
                $table->string('signer_name', 160)->nullable()->after('signer_role');
                $table->string('signer_designation', 160)->nullable()->after('signer_name');
            }
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->unique(['payroll_run_id', 'employee_id'], 'payslips_run_employee_unique');
        });

        $now = now();
        $defaults = [
            'payroll_signatory_name' => 'Mugilvannan L',
            'payroll_signatory_designation' => 'AI Integration Engineer',
        ];
        foreach ($defaults as $key => $value) {
            $current = DB::table('system_settings')->where('key', $key)->value('value');
            if ($current === null || $current === '') {
                DB::table('system_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => $now]);
            }
        }
        // The signatory's login (whose signature PIN signs payslips). Defaults to the
        // super admin login of the named signatory when there is one; editable in Settings → Payroll.
        if (! DB::table('system_settings')->where('key', 'payroll_signatory_user_id')->exists()) {
            $userId = DB::table('users')->where('name', 'System Admin @ Mugil')->value('id');
            if ($userId) {
                DB::table('system_settings')->insert(['key' => 'payroll_signatory_user_id', 'value' => (string) $userId, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropUnique('payslips_run_employee_unique');
        });
        Schema::table('internal_document_signatures', function (Blueprint $table) {
            $table->dropColumn(['signer_name', 'signer_designation']);
        });
        DB::table('system_settings')->where('key', 'payroll_signatory_user_id')->delete();
    }
};
