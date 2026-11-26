<?php

namespace App\Http\Controllers;

use App\Mail\RenewalInvoiceMail;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Document;
use App\Models\FeeRateCard;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\RenewalSchedule;
use App\Models\User;
use App\Models\ZohoInvoice;
use App\Services\PatentInvoiceUinService;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The renewal approve -> invoice -> payment-proof -> confirm loop from the
 * 397/269 pilot plan. Deliberately built on PatentInvoiceIn + ClientLedger,
 * NOT Invoice/Payment — confirmed with the user ("invoice stays the same").
 */
class RenewalActionController extends Controller
{
    /** A follow-up note: any staff member who may see this invoice (their CM / SCM case, circle, or all finance). */
    private function staffMaySee($user, PatentInvoiceIn $invoice): bool
    {
        $query = PatentInvoiceIn::query()->whereKey($invoice->id);
        if (! \App\Support\DataAccess::canOpenFinance($user)) {
            return false;
        }
        \App\Support\DataAccess::scopeFinance($query, $user);

        return $query->exists();
    }

    /**
     * Sums the correct fee_rate_cards RNF band for each specific renewal year
     * being approved — not a flat rate x year-count (a multi-year approval can
     * span two bands, e.g. years 6 and 7 cross IN's 2nd-6th/7th-10th boundary).
     * India's professional fee is flat per renewal transaction regardless of
     * how many years are covered; EP's accrues per year — see
     * FeeRateCard.professional_fee_charge_basis and the seeded rate data.
     *
     * @param Collection<int, int> $years the actual renewal_year values being approved
     */
    private function renewalTotals(string $jurisdiction, ?string $clientTier, Collection $years): array
    {
        $tier = $clientTier === 'individual_startup_msme' ? 'discounted' : 'standard';
        // US: maintenance fees (MFE) banded at 3.5 / 7.5 / 11.5 years, stored as renewal_year
        // 4 / 8 / 12. Elsewhere: 'REN' is what real project data actually carries; 'RNF' is the
        // dictionary name in config/project_import_codes.php — accept either.
        $isUs = strtoupper($jurisdiction) === 'US';
        if ($isUs) {
            $years = $years->map(fn ($year) => (float) $year - 0.5);
        }
        $rows = FeeRateCard::where('jurisdiction', $jurisdiction)->whereIn('service_code', $isUs ? ['MFE'] : ['REN', 'RNF'])
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('entity_tier', $tier)->orWhereNull('entity_tier'))
            ->get();

        $govt = 0.0; $profFlat = null; $profPerYear = 0.0; $currency = null;
        foreach ($years as $year) {
            $row = $rows->first(fn ($r) => $year >= $r->year_from && $year <= $r->year_to && $r->entity_tier === $tier)
                ?? $rows->first(fn ($r) => $year >= $r->year_from && $year <= $r->year_to);
            if (! $row) {
                continue; // unmapped band for this jurisdiction — leave at 0, staff reconciles manually
            }
            $govt += (float) $row->govt_fee_amount;
            $currency = $row->govt_fee_currency;
            if ($row->professional_fee_charge_basis === 'flat_per_transaction') {
                $profFlat = (float) $row->professional_fee_amount;
            } else {
                $profPerYear += (float) $row->professional_fee_amount;
            }
        }

        return [
            'government_fee'   => round($govt, 2),
            'professional_fee' => round($profFlat ?? $profPerYear, 2),
            'currency'         => $currency ?? 'INR',
        ];
    }

    /** Append a running-balance row to client_ledger, matching FinancialController's lock-and-append pattern. */
    private function appendLedgerEntry(int $clientId, string $documentType, string $reference, float $debit, float $credit, ?string $notes = null): void
    {
        $lastBalance = (float) (DB::table('client_ledger')
            ->where('client_id', $clientId)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('balance') ?? 0);

        DB::table('client_ledger')->insert([
            'client_id'           => $clientId,
            'transaction_date'    => now()->toDateString(),
            'document_type'       => $documentType,
            'document_reference'  => $reference,
            'debit'               => $debit,
            'credit'              => $credit,
            'balance'             => $lastBalance + $debit - $credit,
            'notes'               => $notes,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    /**
     * Client admin approves renewing N years for a case. Sums the correct
     * fee_rate_cards band per year (see renewalTotals()), raises a PatentInvoiceIn row
     * using the same UIN scheme as staff-raised invoices, links the covered
     * RenewalSchedule years to it, logs the debit on the client ledger,
     * self-resolves an Approval audit record, and emails the invoice.
     */
    public function approve(Request $request, $projectId)
    {
        $user = $request->user();
        if ($user->role !== 'client_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
        if (! $client) {
            return response()->json(['message' => 'No client record linked to your account.'], 404);
        }

        $validated = $request->validate(['years' => 'required|integer|min:1|max:20']);

        $project = Project::with('patentApplication')->where('client_id', $client->id)->findOrFail($projectId);
        $application = $project->patentApplication;
        if (! $application) {
            return response()->json(['message' => 'This case has no linked patent application yet.'], 422);
        }

        $years = $validated['years'];

        $result = DB::transaction(function () use ($application, $project, $client, $user, $years) {
            // Cover the next $years unpaid renewal years, oldest first; create
            // schedule rows if fewer than $years already exist.
            $schedules = RenewalSchedule::where('patent_application_id', $application->id)
                ->where('status', 'Unpaid')
                ->whereNull('patent_invoice_in_id')
                ->orderBy('renewal_year')
                ->lockForUpdate()
                ->limit($years)
                ->get();

            // US maintenance fees are fixed (3.5 / 7.5 / 11.5 years); only India-style annual
            // renewals get extra years created on demand.
            $isUs = strtoupper((string) ($project->patent_office_code ?: $application->jurisdiction)) === 'US';
            if ($isUs && $schedules->isEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['years' => 'No maintenance fee is due on this patent right now.']);
            }
            $nextYear = (int) (RenewalSchedule::where('patent_application_id', $application->id)->max('renewal_year') ?? 2) + 1;
            while (! $isUs && $schedules->count() < $years) {
                $schedules->push(RenewalSchedule::create([
                    'patent_application_id' => $application->id,
                    'renewal_year'           => $nextYear,
                    'due_date'               => now()->addYears($nextYear)->toDateString(),
                    'status'                 => 'Unpaid',
                ]));
                $nextYear++;
            }

            $totals = $this->renewalTotals($project->patent_office_code, $client->fee_entity_tier, $schedules->pluck('renewal_year'));
            $govtFee = $totals['government_fee'];
            $profFee = $totals['professional_fee'];
            $currency = $totals['currency'];

            $invoice = PatentInvoiceIn::create([
                'type'                 => 'invoice',
                'status'               => 'Sent',
                'payment_status'       => 'Pending',
                'project_id'           => $project->id,
                'client_id'            => $client->id,
                'created_by_id'        => $user->id,
                'docket_number'        => $project->docket_number,
                'invoice_uin'          => app(PatentInvoiceUinService::class)->next($project->docket_number, 'invoice'),
                'invoice_date'         => now()->toDateString(),
                'invention_title'      => $project->invention_title ?: $project->project_name,
                'service_code'         => $project->service_code,
                'client_name'          => $client->company_name ?? $client->legal_name,
                'patent_office_application_number' => $application->application_number,
                'patent_office_fees'   => $govtFee,
                'service_fees'         => $profFee,
                'other_expenses'       => 0,
                // GST line items are out of scope here — renewal invoices raised through
                // this flow show a flat government + professional fee total, not a
                // state-of-supply-driven GST breakdown like staff-raised invoices.
                'igst_amount'          => 0,
                'cgst_amount'          => 0,
                'sgst_amount'          => 0,
                'invoice_amount'       => $govtFee + $profFee,
                'net_revenue'          => $profFee,
                'currency'             => $currency,
                'remarks'              => "Renewal for {$years} year(s), approved via client portal.",
            ]);

            foreach ($schedules as $schedule) {
                $schedule->update(['patent_invoice_in_id' => $invoice->id]);
            }

            $this->appendLedgerEntry($client->id, 'Invoice', $invoice->invoice_uin, (float) $invoice->invoice_amount, 0, "Renewal invoice — {$years} year(s), {$project->docket_number}");

            // Single self-service action, not a two-party review — record and
            // resolve the Approval in the same transaction rather than waiting
            // on a second party (reuses Approval's existing client audit trail).
            Approval::create([
                'requester_id'  => $user->id,
                'approver_id'   => $user->id,
                'client_id'     => $client->id,
                'type'          => 'client',
                'title'         => "Renewal approved — {$project->docket_number}",
                'description'   => "{$years} year(s) renewal approved and invoiced ({$invoice->invoice_uin}).",
                'subject_type'  => 'PatentInvoiceIn',
                'subject_id'    => $invoice->id,
                'status'        => 'Approved',
                'comments'      => 'Approved via client portal renewal flow.',
            ]);

            AuditLog::create([
                'user_id' => $user->id, 'action' => 'renewal_approved',
                'subject_type' => 'PatentInvoiceIn', 'subject_id' => $invoice->id,
                'metadata' => ['project_id' => $project->id, 'years' => $years, 'amount' => $invoice->invoice_amount],
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
            ]);

            return $invoice;
        });

        // Best-effort — a mail failure shouldn't roll back an already-committed invoice.
        try {
            $recipients = ClientContact::where('client_id', $client->id)->whereNotNull('email')->pluck('email');
            if ($recipients->isNotEmpty()) {
                Mail::to($recipients->all())->send(new RenewalInvoiceMail(
                    invoice: $result,
                    clientName: $client->company_name ?? $client->legal_name ?? 'Client',
                    portalUrl: config('app.url') . '/financial',
                ));
            }
        } catch (\Throwable) {
            // Swallow — the invoice/ledger/approval already committed; email is best-effort.
        }

        return response()->json($result->load('project:id,docket_number,project_name'), 201);
    }

    /**
     * Auto-generates a renewal QUOTE 90 days before a RenewalSchedule row's due date (see
     * SendDeadlineRemindersCommand::scanRenewals()) — an estimate only, explicitly marked
     * subject to change, never an invoice. Idempotent: returns null (no-op) if this renewal
     * year already has a linked PatentInvoiceIn. The client approves it via approveQuote()
     * below; staff still raise the real, final invoice manually via the existing
     * PatentInvoiceController::convert() quote->invoice action — this method never does that.
     */
    public function autoCreateQuote(RenewalSchedule $renewal): ?PatentInvoiceIn
    {
        if ($renewal->patent_invoice_in_id) {
            return null;
        }

        $application = $renewal->application;
        $client = $application?->client;
        if (! $application || ! $client) {
            return null;
        }
        $project = $application->projects->first();

        $totals = $this->renewalTotals((string) ($project?->patent_office_code ?: $application->jurisdiction), $client->fee_entity_tier, collect([$renewal->renewal_year]));
        $docketForUin = $project?->docket_number ?? $application->application_number ?? "APP{$application->id}";

        return DB::transaction(function () use ($application, $project, $client, $renewal, $totals, $docketForUin) {
            $quote = PatentInvoiceIn::create([
                'type'                 => 'quote',
                'status'               => 'Sent',
                'payment_status'       => 'Pending',
                'project_id'           => $project?->id,
                'client_id'            => $client->id,
                'created_by_id'        => null, // system-generated
                'docket_number'        => $project?->docket_number ?? $docketForUin,
                'invoice_uin'          => app(PatentInvoiceUinService::class)->next($docketForUin, 'quote'),
                'invoice_date'         => now()->toDateString(),
                'invention_title'      => $project?->invention_title ?: $application->title,
                'service_code'         => $project?->service_code,
                'client_name'          => $client->company_name ?? $client->legal_name,
                'patent_office_application_number' => $application->application_number,
                'patent_office_fees'   => $totals['government_fee'],
                'service_fees'         => $totals['professional_fee'],
                'other_expenses'       => 0,
                'igst_amount'          => 0,
                'cgst_amount'          => 0,
                'sgst_amount'          => 0,
                'invoice_amount'       => $totals['government_fee'] + $totals['professional_fee'],
                'net_revenue'          => $totals['professional_fee'],
                'currency'             => $totals['currency'],
                'remarks'              => "Auto-generated estimate for {$renewal->display_label} — subject to change based on government fees and internal company policy. Not a final invoice; staff will raise the actual invoice separately.",
            ]);

            $renewal->update(['patent_invoice_in_id' => $quote->id]);

            AuditLog::create([
                'user_id'      => null,
                'action'       => 'auto_create_renewal_quote',
                'subject_type' => 'PatentInvoiceIn',
                'subject_id'   => $quote->id,
                'metadata'     => ['renewal_id' => $renewal->id, 'renewal_year' => $renewal->renewal_year, 'amount' => $quote->invoice_amount],
                'ip_address'   => null,
                'user_agent'   => 'system:reminders:send-deadlines',
            ]);

            return $quote;
        });
    }

    /**
     * Client approves an auto-generated renewal quote — marks it Accepted (the same status
     * staff's quote-conversion action uses) but does NOT create an invoice itself; the
     * auto-quote is only ever an estimate. Notifies the case's assigned team plus every
     * super_admin/partner/director/paralegal that staff can now raise the final invoice.
     */
    public function approveQuote(Request $request, $invoiceId)
    {
        $user = $request->user();
        if (! in_array($user->role, ['client', 'client_admin', 'client_finance'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
        if (! $client) {
            return response()->json(['message' => 'No client record linked to your account.'], 404);
        }

        $quote = PatentInvoiceIn::where('client_id', $client->id)->where('type', 'quote')->findOrFail($invoiceId);
        if ($quote->status === 'Cancelled') {
            return response()->json(['message' => 'This quote has been cancelled.'], 422);
        }

        $quote->update(['status' => 'Accepted']);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'client_approve_renewal_quote',
            'subject_type' => 'PatentInvoiceIn', 'subject_id' => $quote->id,
            'metadata' => ['docket_number' => $quote->docket_number, 'invoice_uin' => $quote->invoice_uin],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        $project = $quote->project_id ? Project::find($quote->project_id) : null;
        $recipientIds = collect([
            $project?->assigned_partner_id, $project?->assigned_manager_id,
            $project?->secondary_manager_id, $project?->patent_engineer_id,
        ])->merge(User::whereIn('role', ['super_admin', 'partner', 'director', 'paralegal'])->pluck('id'))
          ->filter()->unique();

        Notifier::push(
            $recipientIds,
            'renewal',
            "Client approved renewal quote: {$quote->docket_number}",
            ($client->company_name ?? $client->legal_name ?? 'Client') . " approved the renewal quote {$quote->invoice_uin} for {$quote->docket_number}. Raise the final invoice when ready.",
            '/financial',
            ['invoice_id' => $quote->id, 'project_id' => $project?->id],
        );

        return response()->json($quote->fresh());
    }

    /** Client uploads a payment proof document (already uploaded via POST /documents) against a pending renewal invoice. */
    public function submitProof(Request $request, $invoiceId)
    {
        $user = $request->user();
        if (! in_array($user->role, ['client', 'client_admin', 'client_finance'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
        if (! $client) {
            return response()->json(['message' => 'No client record linked to your account.'], 404);
        }

        $validated = $request->validate(['document_id' => 'required|integer|exists:documents,id']);

        $invoice = PatentInvoiceIn::where('client_id', $client->id)->findOrFail($invoiceId);
        if ($invoice->payment_status === 'Confirmed') {
            return response()->json(['message' => 'This invoice has already been confirmed as paid.'], 422);
        }

        $document = Document::findOrFail($validated['document_id']);
        if ($document->client_id !== $client->id) {
            return response()->json(['message' => 'That document does not belong to your account.'], 403);
        }

        $invoice->update([
            'payment_status'    => 'Proof Submitted',
            'proof_document_id' => $document->id,
        ]);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'renewal_proof_submitted',
            'subject_type' => 'PatentInvoiceIn', 'subject_id' => $invoice->id,
            'metadata' => ['document_id' => $document->id],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($invoice->fresh());
    }

    /** Staff confirms payment receipt — flips payment_status, linked RenewalSchedule rows, and appends the credit to client_ledger. */
    public function confirmReceipt(Request $request, $invoiceId)
    {
        $user = $request->user();
        if (! \App\Support\DataAccess::canActOnFinance($user) && ! $user->isGalvanizer()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $invoice = PatentInvoiceIn::findOrFail($invoiceId);
        if ($user->isGalvanizer() && $invoice->project && ! $user->canAccessCircle($invoice->project->circle)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($invoice->payment_status === 'Confirmed') {
            return response()->json(['message' => 'Already confirmed.'], 422);
        }
        if ($invoice->isFromZoho()) {
            // Zoho owns this invoice's payments: recording it there updates the portal within minutes.
            return response()->json(['message' => 'Record this payment in Zoho Books; the portal updates automatically.'], 422);
        }

        DB::transaction(function () use ($invoice, $user) {
            $confirmedAt = now();
            $invoice->update(['payment_status' => 'Confirmed', 'payment_confirmed_at' => $confirmedAt]);
            $invoice->renewalSchedules()->update(['status' => 'Paid', 'paid_at' => $confirmedAt]);

            $this->appendLedgerEntry($invoice->client_id, 'Payment', $invoice->invoice_uin, 0, (float) $invoice->invoice_amount, "Renewal payment confirmed — {$invoice->docket_number}");

            AuditLog::create([
                'user_id' => $user->id, 'action' => 'renewal_payment_confirmed',
                'subject_type' => 'PatentInvoiceIn', 'subject_id' => $invoice->id,
                'metadata' => ['amount' => $invoice->invoice_amount],
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
            ]);
        });

        return response()->json($invoice->fresh());
    }

    /** Interim free-text status update while a renewal invoice is pending ("will pay by 13 Aug") — staff or the client's own portal users. */
    public function postStatusNote(Request $request, $invoiceId)
    {
        $user = $request->user();
        $invoice = PatentInvoiceIn::findOrFail($invoiceId);

        if ($user->isClientRole()) {
            $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
            if (! $client || $invoice->client_id !== $client->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        } elseif (! $this->staffMaySee($user, $invoice)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate(['status_note' => 'required|string|max:500', 'promised_on' => 'nullable|date|after_or_equal:today']);
        // A promise-to-pay date is recorded by staff (it pauses reminders until then).
        $promised = $user->isClientRole() ? null : ($validated['promised_on'] ?? null);

        \Illuminate\Support\Facades\DB::transaction(function () use ($invoice, $validated, $user, $promised) {
            $invoice->update([
                'status_note'       => $validated['status_note'],
                'status_note_by_id' => $user->id,
                'status_note_at'    => now(),
            ] + ($promised ? ['promised_on' => $promised, 'promise_note' => $validated['status_note']] : []));
            \App\Models\InvoiceFollowUp::create(['patent_invoice_in_id' => $invoice->id, 'user_id' => $user->id,
                'note' => $validated['status_note'], 'promised_on' => $promised]);
        });

        return response()->json($invoice->fresh());
    }

    /** The follow-up history of an invoice (newest first). */
    public function followUps(Request $request, int $invoiceId)
    {
        $user = $request->user();
        $invoice = PatentInvoiceIn::findOrFail($invoiceId);
        abort_if($user->isClientRole() || ! $this->staffMaySee($user, $invoice), 403);

        return response()->json(\App\Models\InvoiceFollowUp::with('user:id,name')->where('patent_invoice_in_id', $invoice->id)->latest('id')->limit(100)->get()
            ->map(fn ($f) => ['id' => $f->id, 'note' => $f->note, 'promised_on' => $f->promised_on?->toDateString(), 'by' => $f->user?->name, 'at' => $f->created_at?->toIso8601String()]));
    }

    /**
     * Pending Payments list — renewal invoices awaiting proof/confirmation.
     * Client roles see their own; staff see all (or their circle, if galvanizer).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = PatentInvoiceIn::with(['project:id,docket_number,project_name', 'client:id,company_name,legal_name,client_code,contact_name,contact_email,phone',
                'proofDocument:id,file_name,storage_path', 'waiverRequestedBy:id,name'])
            ->whereNotNull('payment_status')
            ->whereNotIn('status', ['Draft', 'Cancelled']);

        if ($user->isClientRole()) {
            $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
            if (! $client) {
                return response()->json(['message' => 'No client record linked to your account.'], 404);
            }
            $query->where('client_id', $client->id);
        } elseif (! \App\Support\DataAccess::canOpenFinance($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        } else {
            // All finance, their circle (galvanizer) or their CM / SCM cases.
            \App\Support\DataAccess::scopeFinance($query, $user);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->get('payment_status'));
        } else {
            // Every unpaid invoice in the register: portal renewals and invoices imported from Zoho
            // (a Zoho invoice is pending while Zoho shows a balance). Confirmed = paid.
            $query->where('payment_status', '!=', 'Confirmed')
                ->where(fn ($q) => $q->whereNull('balance_due')->orWhere('balance_due', '>', 0));
        }
        if ($request->filled('search')) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $request->get('search'))).'%';
            $query->where(fn ($q) => $q->where('invoice_uin', 'ilike', $like)->orWhere('client_name', 'ilike', $like)
                ->orWhere('docket_number', 'ilike', $like)->orWhere('invention_title', 'ilike', $like)
                ->orWhereHas('client', fn ($c) => $c->where('client_code', 'ilike', $like)->orWhere('company_name', 'ilike', $like)->orWhere('legal_name', 'ilike', $like)));
        }
        if ($request->boolean('overdue')) {
            $query->whereNotNull('due_date')->where('due_date', '<', now()->toDateString());
        }
        // Staff follow-up filters: one client, an ageing band, a date or date range, waivers to decide.
        if (! $user->isClientRole() && $request->filled('client_id')) {
            $query->where('client_id', (int) $request->get('client_id'));
        }
        $today = now()->startOfDay();
        $age = (string) $request->get('age', '');
        if ($age !== '') {
            $daysAgo = fn (int $d) => $today->copy()->subDays($d)->toDateString();
            match ($age) {
                'not_due' => $query->where(fn ($q) => $q->whereNull('due_date')->orWhere('due_date', '>=', $today->toDateString())),
                '1_30' => $query->where('due_date', '<', $today->toDateString())->where('due_date', '>=', $daysAgo(30)),
                '31_60' => $query->where('due_date', '<', $daysAgo(30))->where('due_date', '>=', $daysAgo(60)),
                '61_90' => $query->where('due_date', '<', $daysAgo(60))->where('due_date', '>=', $daysAgo(90)),
                '90_plus' => $query->where('due_date', '<', $daysAgo(90)),
                default => null,
            };
        }
        $dateField = $request->get('date_field') === 'due' ? 'due_date' : 'invoice_date';
        if ($request->filled('date')) {
            $query->whereDate($dateField, $request->date('date')->toDateString());
        } else {
            if ($request->filled('from')) {
                $query->whereDate($dateField, '>=', $request->date('from')->toDateString());
            }
            if ($request->filled('to')) {
                $query->whereDate($dateField, '<=', $request->date('to')->toDateString());
            }
        }
        if ($request->boolean('waivers')) {
            $query->where('settlement', 'waiver_requested');
        }

        $balanceSql = 'COALESCE(balance_due, invoice_amount)';
        $totals = (clone $query)->reorder()->select('currency')->selectRaw("COALESCE(SUM({$balanceSql}), 0) AS amount")->groupBy('currency')
            ->pluck('amount', 'currency')->map(fn ($amount) => round((float) $amount, 2));
        // Advances earmarked for quotes (money already received): the due list shows the net.
        $advanceOnQuotes = round((float) \App\Models\AdvanceQuoteLink::whereIn('patent_invoice_in_id', (clone $query)->reorder()->where('type', 'quote')->select('id'))->sum('amount'), 2);
        if ($advanceOnQuotes > 0) {
            $totals['INR'] = round(max(0, (float) ($totals['INR'] ?? 0) - $advanceOnQuotes), 2);
        }
        $overdue = (clone $query)->whereNotNull('due_date')->where('due_date', '<', now()->toDateString())->count();
        $staff = ! $user->isClientRole();
        $scoped = fn () => PatentInvoiceIn::query()->when($staff, fn ($q) => \App\Support\DataAccess::scopeFinance($q, $user));

        // Most overdue first, then the newest.
        $page = $query->orderByRaw('due_date ASC NULLS LAST')->orderByDesc('invoice_date')->orderByDesc('id')
            ->paginate(min((int) $request->get('per_page', 25), 100));
        $links = \App\Models\AdvanceQuoteLink::whereIn('patent_invoice_in_id', $page->getCollection()->where('type', 'quote')->pluck('id'))
            ->get()->groupBy('patent_invoice_in_id');
        $page->getCollection()->transform(function (PatentInvoiceIn $row) use ($today, $links) {
            $row->setAttribute('balance', (float) ($row->balance_due ?? $row->invoice_amount));
            if ($row->type === 'quote' && isset($links[$row->id])) {
                // Part of an advance already received is earmarked for this quote (INR).
                $advance = round((float) $links[$row->id]->sum('amount'), 2);
                $rate = (float) ($row->exchange_rate ?? 1) ?: 1.0;
                $row->setAttribute('advance_linked', $advance);
                $row->setAttribute('advance_payments', $links[$row->id]->pluck('payment_number')->filter()->unique()->values());
                $row->setAttribute('net_due', round(max(0, (float) $row->getAttribute('balance') - $advance / $rate), 2));
            }
            $row->setAttribute('days_overdue', $row->due_date && $row->due_date->lt($today) ? (int) $row->due_date->diffInDays($today) : 0);
            // A promise to pay that has passed while the invoice is still unpaid.
            $row->setAttribute('promise_broken', $row->promised_on && $row->promised_on->lt($today));
            if ($row->isFromZoho()) {
                // Internal fees never go to the client side.
                $row->makeHidden(['attorney_fees', 'consultant_fees', 'referral_fees', 'net_revenue']);
            }

            return $row;
        });

        return response()->json(array_merge($page->toArray(), [
            'pending_totals' => $totals,
            'pending_count' => $page->total(),
            'advance_on_quotes' => $advanceOnQuotes,
            'overdue_count' => $overdue,
            // Staff only: waivers to decide, settlements not yet in Zoho, waived this year, clients for the filter.
            'waivers_pending' => $staff ? $scoped()->where('settlement', 'waiver_requested')->count() : 0,
            'writeback_pending' => $staff ? $scoped()->whereIn('zoho_writeback', ['pending', 'failed'])->count() : 0,
            'waived_this_year' => $staff ? round((float) $scoped()->where('waived_amount', '>', 0)->whereYear('settled_at', now()->year)
                ->sum(\Illuminate\Support\Facades\DB::raw('waived_amount * COALESCE(NULLIF(exchange_rate, 0), 1)')), 2) : 0,
            'clients' => $staff ? Client::whereIn('id', $scoped()->whereNotNull('client_id')->where('type', 'invoice')->whereNotIn('status', ['Draft', 'Cancelled'])
                ->where('payment_status', '!=', 'Confirmed')->where(fn ($q) => $q->whereNull('balance_due')->orWhere('balance_due', '>', 0))->select('client_id'))
                ->orderBy('legal_name')->get(['id', 'legal_name', 'company_name', 'client_code'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->legal_name ?: $c->company_name, 'code' => $c->client_code])->values() : [],
            'can' => [
                'mark_paid' => \App\Services\InvoiceSettlement::canPay($user),
                'request_waiver' => \App\Services\InvoiceSettlement::canRequestWaiver($user),
                'approve_waiver' => in_array($user->role, \App\Services\InvoiceSettlement::WAIVE_APPROVE_ROLES, true),
            ],
        ]));
    }
}
