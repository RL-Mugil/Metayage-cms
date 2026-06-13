<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'client_type')) {
                $table->string('client_type')->nullable()->after('client_code');
            }
            if (! Schema::hasColumn('clients', 'legal_name')) {
                $table->string('legal_name')->nullable()->after('company_name');
            }
            if (! Schema::hasColumn('clients', 'entity_subtype')) {
                $table->string('entity_subtype')->nullable()->after('entity_type');
            }
            if (! Schema::hasColumn('clients', 'pan_number')) {
                $table->string('pan_number', 10)->nullable()->after('entity_subtype');
            }
            if (! Schema::hasColumn('clients', 'cin_number')) {
                $table->string('cin_number', 21)->nullable()->after('pan_number');
            }
            if (! Schema::hasColumn('clients', 'has_gstin')) {
                $table->boolean('has_gstin')->default(false)->after('cin_number');
            }
            if (! Schema::hasColumn('clients', 'gstin')) {
                $table->string('gstin', 15)->nullable()->after('has_gstin');
            }
            if (! Schema::hasColumn('clients', 'gst_type')) {
                $table->string('gst_type')->nullable()->after('gstin');
            }
            if (! Schema::hasColumn('clients', 'nationality')) {
                $table->string('nationality', 100)->nullable()->after('gst_type');
            }
            if (! Schema::hasColumn('clients', 'state')) {
                $table->string('state', 100)->nullable()->after('nationality');
            }
            if (! Schema::hasColumn('clients', 'address')) {
                $table->text('address')->nullable()->after('state');
            }
            if (! Schema::hasColumn('clients', 'contact_name')) {
                $table->string('contact_name')->nullable()->after('address');
            }
            if (! Schema::hasColumn('clients', 'contact_email')) {
                $table->string('contact_email')->nullable()->after('contact_name');
            }
            if (! Schema::hasColumn('clients', 'phone')) {
                $table->string('phone', 20)->nullable()->after('contact_email');
            }
            if (! Schema::hasColumn('clients', 'bank_name')) {
                $table->string('bank_name', 255)->nullable()->after('currency_preference');
            }
            if (! Schema::hasColumn('clients', 'bank_account')) {
                $table->string('bank_account', 50)->nullable()->after('bank_name');
            }
            if (! Schema::hasColumn('clients', 'bank_ifsc')) {
                $table->string('bank_ifsc', 20)->nullable()->after('bank_account');
            }
            if (! Schema::hasColumn('clients', 'referred_by_code')) {
                $table->string('referred_by_code', 10)->nullable()->after('sla_tier');
            }
            if (! Schema::hasColumn('clients', 'accounts_person')) {
                $table->string('accounts_person', 255)->nullable()->after('referred_by_code');
            }
            if (! Schema::hasColumn('clients', 'remarks')) {
                $table->text('remarks')->nullable()->after('accounts_person');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $cols = [
                'client_type', 'legal_name', 'entity_subtype', 'pan_number', 'cin_number',
                'has_gstin', 'gstin', 'gst_type', 'nationality', 'state', 'address',
                'contact_name', 'contact_email', 'phone',
                'bank_name', 'bank_account', 'bank_ifsc',
                'referred_by_code', 'accounts_person', 'remarks',
            ];
            $existing = array_filter($cols, fn ($c) => Schema::hasColumn('clients', $c));
            if ($existing) {
                $table->dropColumn(array_values($existing));
            }
        });
    }
};
