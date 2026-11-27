<?php

use App\Models\PatentInvoiceIn;
use App\Services\QuoteApprovals;
use Illuminate\Database\Migrations\Migration;

/** Quotes already sent and still open wait in each client's Approvals tab (no notification for these). */
return new class extends Migration
{
    public function up(): void
    {
        PatentInvoiceIn::query()->openQuotes()->where('patent_invoices_in.status', 'Sent')->whereNotNull('client_id')
            ->orderBy('id')->each(fn (PatentInvoiceIn $quote) => QuoteApprovals::ensure($quote, false));
    }

    public function down(): void
    {
        \App\Models\Approval::where('type', 'client')->where('subject_type', QuoteApprovals::SUBJECT)->where('status', 'Pending')->delete();
    }
};
