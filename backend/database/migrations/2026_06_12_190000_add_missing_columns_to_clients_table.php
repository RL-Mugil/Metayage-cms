<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('client_type')->nullable()->after('client_code');
            $table->string('legal_name')->nullable()->after('company_name');
            $table->string('entity_subtype')->nullable()->after('entity_type');
            $table->string('pan_number', 10)->nullable()->after('entity_subtype');
            $table->string('cin_number', 21)->nullable()->after('pan_number');
            $table->boolean('has_gstin')->default(false)->after('cin_number');
            $table->string('gstin', 15)->nullable()->after('has_gstin');
            $table->string('gst_type')->nullable()->after('gstin');
            $table->string('nationality', 100)->nullable()->after('gst_type');
            $table->string('state', 100)->nullable()->after('nationality');
            $table->text('address')->nullable()->after('state');
            $table->string('contact_name')->nullable()->after('address');
            $table->string('contact_email')->nullable()->after('contact_name');
            $table->string('phone', 20)->nullable()->after('contact_email');
            $table->string('bank_name', 255)->nullable()->after('currency_preference');
            $table->string('bank_account', 50)->nullable()->after('bank_name');
            $table->string('bank_ifsc', 20)->nullable()->after('bank_account');
            $table->string('referred_by_code', 10)->nullable()->after('sla_tier');
            $table->string('accounts_person', 255)->nullable()->after('referred_by_code');
            $table->text('remarks')->nullable()->after('accounts_person');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'client_type', 'legal_name', 'entity_subtype', 'pan_number', 'cin_number',
                'has_gstin', 'gstin', 'gst_type', 'nationality', 'state', 'address',
                'contact_name', 'contact_email', 'phone',
                'bank_name', 'bank_account', 'bank_ifsc',
                'referred_by_code', 'accounts_person', 'remarks',
            ]);
        });
    }
};
