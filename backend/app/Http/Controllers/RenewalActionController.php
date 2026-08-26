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
use App\Services\PatentInvoiceUinService;
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
    private const STAFF_CONFIRM_ROLES = ['super_admin', 'partner', 'manager', 'finance', 'galvanizer'];

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
        // 'REN' is what real project data actually carries; 'RNF' is the
        // dictionary name in config/project_import_codes.php — accept either.
        $rows = FeeRateCard::where('jurisdiction', $jurisdiction)->whereIn('service_code', ['REN', 'RNF'])
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

            $nextYear = (int) (RenewalSchedule::where('patent_application_id', $application->id)->max('renewal_year') ?? 2) + 1;
            while ($schedules->count() < $years) {
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
        if (! in_array($user->role, self::STAFF_CONFIRM_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $invoice = PatentInvoiceIn::findOrFail($invoiceId);
        if ($user->isGalvanizer() && $invoice->project && ! $user->canAccessCircle($invoice->project->circle)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($invoice->payment_status === 'Confirmed') {
            return response()->json(['message' => 'Already confirmed.'], 422);
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
        } elseif (! in_array($user->role, self::STAFF_CONFIRM_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate(['status_note' => 'required|string|max:500']);

        $invoice->update([
            'status_note'       => $validated['status_note'],
            'status_note_by_id' => $user->id,
            'status_note_at'    => now(),
        ]);

        return response()->json($invoice->fresh());
    }

    /**
     * Pending Payments list — renewal invoices awaiting proof/confirmation.
     * Client roles see their own; staff see all (or their circle, if galvanizer).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = PatentInvoiceIn::with(['project:id,docket_number,project_name', 'client:id,company_name,legal_name', 'proofDocument:id,file_name,storage_path'])
            ->whereNotNull('payment_status')
            ->orderByDesc('invoice_date');

        if ($user->isClientRole()) {
            $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
            if (! $client) {
                return response()->json(['message' => 'No client record linked to your account.'], 404);
            }
            $query->where('client_id', $client->id);
        } elseif ($user->isGalvanizer()) {
            $query->whereHas('project', fn ($q) => $user->applyProjectScope($q));
        } elseif (! in_array($user->role, self::STAFF_CONFIRM_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->get('payment_status'));
        }

        return response()->json($query->paginate(min((int) $request->get('per_page', 50), 200)));
    }
}
