<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. Advance ↔ quote: money received and not applied to any invoice (Zoho "unused credit") can
 *    be earmarked for a proforma quote, so the quote shows its net due. Zoho cannot apply a
 *    payment to a quote; this is the portal's own note, settled in Zoho once the quote is invoiced.
 * 2. Zoho opening balances were dated the day they were synced (Zoho sends no date). They are
 *    what was owed before the books moved to Zoho, so they belong on the opening date (31-03-2026).
 */
return new class extends Migration
{
    private const OPENING_DATE = '2026-03-31';

    public function up(): void
    {
        Schema::create('advance_quote_links', function (Blueprint $table) {
            $table->id();
            $table->string('zoho_payment_id', 80);   // the customer payment holding the unused amount
            $table->string('payment_number', 80)->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('patent_invoice_in_id')->constrained('patent_invoices_in')->cascadeOnDelete();   // the quote
            $table->decimal('amount', 15, 2);         // INR
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['zoho_payment_id', 'patent_invoice_in_id']);
            $table->index(['patent_invoice_in_id']);
        });

        DB::table('system_settings')->updateOrInsert(['key' => 'zoho_opening_balance_date'], ['value' => self::OPENING_DATE]);

        foreach (DB::table('zoho_opening_balances')->where('balance_date', '>', self::OPENING_DATE)->orderByDesc('synced_at')->get() as $row) {
            $taken = DB::table('zoho_opening_balances')->where('scope', $row->scope)->where('zoho_contact_id', $row->zoho_contact_id)
                ->where('location_id', $row->location_id)->where('balance_date', self::OPENING_DATE)->exists();
            if ($taken) {
                DB::table('zoho_opening_balances')->where('id', $row->id)->delete();   // an older copy of the same balance
            } else {
                DB::table('zoho_opening_balances')->where('id', $row->id)->update(['balance_date' => self::OPENING_DATE]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_quote_links');
    }
};
