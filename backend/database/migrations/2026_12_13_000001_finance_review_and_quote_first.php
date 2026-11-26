<?php

use App\Models\ZohoSnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance review fixes and quote-first billing:
 * - zoho_payment_index: the few customer-payment fields the portal reads, in plain columns
 *   (snapshot bodies are encrypted; reading them for every page does not scale);
 * - matter_costs.needs_review: costs imported from Zoho wait for Accounts to confirm them;
 * - patent_invoices_in.converted_invoice_id: a quote turned into an invoice (one definition of "open quote");
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoho_payment_index', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 40);
            $table->string('remote_id', 80);
            $table->string('customer_id', 40)->nullable()->index();
            $table->string('customer_name', 255)->nullable();
            $table->string('payment_number', 80)->nullable();
            $table->date('date')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('unused_amount', 15, 2)->default(0);
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->string('currency', 5)->default('INR');
            $table->string('payment_mode', 60)->nullable();
            $table->string('reference', 120)->nullable();
            $table->json('applications')->nullable();   // [{invoice_id, invoice_number, amount_applied, tax_amount_withheld}]
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['scope', 'remote_id']);
            $table->index(['unused_amount']);
        });

        ZohoSnapshot::withTrashed()->where('resource', 'customerpayments')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $snapshot) {
                \App\Models\ZohoPaymentIndex::syncFrom($snapshot);
            }
        });

        Schema::table('matter_costs', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('recoverable');
        });
        DB::table('matter_costs')->whereIn('source', ['zoho_bill', 'zoho_expense'])->update(['needs_review' => true]);

        Schema::table('patent_invoices_in', function (Blueprint $table) {
            $table->foreignId('converted_invoice_id')->nullable()->after('zoho_status')->constrained('patent_invoices_in')->nullOnDelete();
        });
        // Quotes already converted in the portal (convert_quote_to_invoice audit rows).
        foreach (DB::table('audit_logs')->where('action', 'convert_quote_to_invoice')->get(['subject_id', 'metadata']) as $log) {
            $quoteId = (int) (json_decode((string) $log->metadata, true)['from_quote_id'] ?? 0);
            if ($quoteId && DB::table('patent_invoices_in')->where('id', $log->subject_id)->exists()) {
                DB::table('patent_invoices_in')->where('id', $quoteId)->whereNull('converted_invoice_id')->update(['converted_invoice_id' => $log->subject_id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('patent_invoices_in', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_invoice_id');
        });
        Schema::table('matter_costs', function (Blueprint $table) {
            $table->dropColumn('needs_review');
        });
        Schema::dropIfExists('zoho_payment_index');
    }
};
