<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->enum('client_type', ['individual', 'organization'])->default('organization')->after('id');
            $table->boolean('has_gstin')->default(false)->after('client_code');
            $table->string('gstin', 15)->nullable()->after('has_gstin');
            $table->string('gst_type', 20)->nullable()->after('gstin');       // B2B | B2C | Export | Unregistered
            $table->string('nationality', 100)->default('India')->after('gst_type');
            $table->string('legal_name', 255)->nullable()->after('nationality');
            $table->string('contact_name', 255)->nullable()->after('legal_name');
            $table->string('contact_email', 255)->nullable()->after('contact_name');
            $table->string('phone', 20)->nullable()->after('contact_email');
            $table->text('address')->nullable()->after('phone');
            $table->string('state', 100)->nullable()->after('address');
            $table->string('entity_subtype', 100)->nullable()->after('entity_type'); // Pvt Ltd, LLP …
            $table->string('pan_number', 10)->nullable()->after('tax_id');
            $table->string('cin_number', 21)->nullable()->after('pan_number');
            $table->string('bank_name', 255)->nullable()->after('sla_tier');
            $table->string('bank_account', 50)->nullable()->after('bank_name');
            $table->string('bank_ifsc', 20)->nullable()->after('bank_account');
            $table->string('referred_by_code', 10)->nullable()->after('bank_ifsc');
            $table->string('accounts_person', 255)->nullable()->after('referred_by_code');
            $table->text('remarks')->nullable()->after('accounts_person');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'client_type','has_gstin','gstin','gst_type','nationality',
                'legal_name','contact_name','contact_email','phone','address','state',
                'entity_subtype','pan_number','cin_number',
                'bank_name','bank_account','bank_ifsc',
                'referred_by_code','accounts_person','remarks',
            ]);
        });
    }
};
