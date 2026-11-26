<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\Client;
use App\Services\PatentInvoiceUinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PatentInvoiceController extends Controller
{
    // ── GST computation (Karnataka → CGST+SGST, others → IGST) ─────────────────

    private function computeGst(string $stateOfSupply, float $serviceFees): array
    {
        $isKarnataka = strtolower(trim($stateOfSupply)) === 'karnataka';
        if ($isKarnataka) {
            $cgst = round($serviceFees * 0.09, 2);
            $sgst = round($serviceFees * 0.09, 2);
            return ['igst' => 0.0, 'cgst' => $cgst, 'sgst' => $sgst];
        }
        return ['igst' => round($serviceFees * 0.18, 2), 'cgst' => 0.0, 'sgst' => 0.0];
    }

    // ── Invoice UIN serial ────────────────────────────────────────────────────────
    // First invoice for a project/type → docket_number
    // Second → docket_number/1, third → docket_number/2, etc.
    // (extracted to PatentInvoiceUinService so RenewalActionController's renewal
    // invoices use the exact same numbering)

    private function computeUin(string $docketNumber, string $type, ?int $excludeId = null): string
    {
        return app(PatentInvoiceUinService::class)->next($docketNumber, $type, $excludeId);
    }

    // ── Compute derived totals ────────────────────────────────────────────────────

    private function computeTotals(array $data): array
    {
        $pof          = (float) ($data['patent_office_fees'] ?? 0);
        $svc          = (float) ($data['service_fees']       ?? 0);
        $other        = (float) ($data['other_expenses']     ?? 0);
        $state        = $data['state_of_supply'] ?? '';

        // Discount applies to the professional (service) fee only — government
        // fees are statutory and never discounted — and is taken off before GST,
        // so GST is charged on the net service fee, not the pre-discount one.
        $discountPct  = (float) ($data['discount_percentage'] ?? 0);
        $discountAmt  = round($svc * ($discountPct / 100), 2);
        $netSvc       = $svc - $discountAmt;

        $gst          = $this->computeGst($state, $netSvc);
        $invoiceAmt   = $pof + $netSvc + $gst['igst'] + $gst['cgst'] + $gst['sgst'] + $other;
        $netRevenue   = $invoiceAmt
                        - (float) ($data['attorney_fees']   ?? 0)
                        - (float) ($data['consultant_fees'] ?? 0)
                        - (float) ($data['referral_fees']   ?? 0);

        return array_merge($data, [
            'discount_amount' => $discountAmt,
            'igst_amount'    => $gst['igst'],
            'cgst_amount'    => $gst['cgst'],
            'sgst_amount'    => $gst['sgst'],
            'invoice_amount' => round($invoiceAmt, 2),
            'net_revenue'    => round($netRevenue, 2),
        ]);
    }

    // ── LIST ──────────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $user = $request->user();
        $q    = PatentInvoiceIn::with(['project:id,project_code,docket_number', 'client:id,company_name,legal_name,client_code'])
            // Newest document first (imported Zoho records all share one created_at).
            ->orderByRaw('invoice_date DESC NULLS LAST')->orderByDesc('id');

        if ($request->filled('type'))       $q->where('type',       $request->type);
        if ($request->filled('source'))     $q->where('source',     $request->source);
        // Imported rows still needing a look: no client matched, or the fee split not available yet.
        if ($request->boolean('needs_attention')) {
            $q->where(fn ($w) => $w->whereNull('client_id')->orWhere('amount_split_pending', true));
        }
        if ($request->filled('status'))     $q->where('status',     $request->status);
        if ($request->filled('project_id')) $q->where('project_id', (int) $request->project_id);
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($query) use ($s) {
                $query->where('docket_number', 'ilike', "%{$s}%")
                      ->orWhere('invoice_uin',  'ilike', "%{$s}%")
                      ->orWhere('client_name',  'ilike', "%{$s}%")
                      ->orWhere('invention_title', 'ilike', "%{$s}%")
                      ->orWhere('patent_office_application_number', 'ilike', "%{$s}%");
            });
        }

        // Client logins (incl. client finance): their own client only. Staff: all finance, their
        // circle (galvanizer) or their CM / SCM cases — App\Support\DataAccess.
        if ($user->isClientRole()) {
            $q->where('client_id', $user->client_id ?: 0);
        } else {
            \App\Support\DataAccess::scopeFinance($q, $user);
        }

        $perPage = min((int) $request->get('per_page', 50), 200);
        $result  = $q->paginate($perPage);

        // Strip internal fields for client roles
        $isInternal = \App\Support\DataAccess::seesAllFinance($user) || $user->isGalvanizer();

        $data = $result->map(function ($r) use ($isInternal) {
            $arr = $r->toArray();
            if (!$isInternal) {
                unset($arr['attorney_fees'], $arr['consultant_fees'], $arr['referral_fees'], $arr['net_revenue']);
            }
            return $arr;
        });

        return response()->json([
            'data'         => $data,
            'total'        => $result->total(),
            'per_page'     => $result->perPage(),
            'current_page' => $result->currentPage(),
            'last_page'    => $result->lastPage(),
        ]);
    }

    // ── CREATE ────────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless(\App\Support\DataAccess::canActOnFinance($user) || $user->isGalvanizer(), 403, 'Only Accounts, a Director, a System Admin or someone on the Financial access list can change invoices.');

        $v = $request->validate([
            'type'                             => 'required|in:invoice,quote',
            'project_id'                       => 'required|exists:projects,id',
            'docket_number'                    => 'required|string|max:70',
            'invoice_date'                     => 'required|date',
            'tax_invoice_date'                 => 'nullable|date',
            'tax_serial_number'                => 'nullable|string|max:60',
            'client_code_prefix'               => 'nullable|string|max:10',
            'invention_number'                 => 'nullable|string|max:10',
            'patent_office_code'               => 'nullable|string|max:10',
            'first_inventor_name'              => 'nullable|string|max:255',
            'invention_title'                  => 'nullable|string|max:500',
            'service_code'                     => 'nullable|string|max:20',
            'client_name'                      => 'nullable|string|max:255',
            'client_reference'                 => 'nullable|string|max:50',
            'state_of_supply'                  => 'nullable|string|max:100',
            'entity_status'                    => 'nullable|string|max:150',
            'patent_office_application_number' => 'nullable|string|max:150',
            'additional_information'           => 'nullable|string',
            'patent_office_acknowledgement'    => 'nullable|string',
            'remarks'                          => 'nullable|string',
            'uin_old'                          => 'nullable|string|max:80',
            'uin_old_2'                        => 'nullable|string|max:80',
            'patent_office_fees'               => 'required|numeric|min:0',
            'service_fees'                     => 'required|numeric|min:0',
            'discount_percentage'              => 'nullable|numeric|min:0|max:100',
            'other_expenses'                   => 'required|numeric|min:0',
            'attorney_fees'                    => 'nullable|numeric|min:0',
            'consultant_fees'                  => 'nullable|numeric|min:0',
            'referral_fees'                    => 'nullable|numeric|min:0',
            'currency'                         => 'nullable|string|max:5',
        ]);

        // Derive client_id from project
        $project  = Project::findOrFail($v['project_id']);
        if ($user->isGalvanizer() && ! $user->canAccessCircle($project->circle)) {
            abort(403);
        }
        $clientId = $project->client_id;
        // Quote-first billing: a tax invoice before the client has paid needs a reason (logged).
        if (($v['type'] ?? 'invoice') === 'invoice') {
            \App\Services\QuoteFirst::check($clientId, null, $request->input('override_reason'), $user, 'new invoice');
        }

        $record = DB::transaction(function () use ($v, $user, $clientId) {
            $data = $this->computeTotals($v);
            $data['invoice_uin']    = $this->computeUin($v['docket_number'], $v['type']);
            $data['client_id']      = $clientId;
            $data['created_by_id']  = $user->id;
            $data['status']         = 'Draft';
            $data['currency']       = $v['currency'] ?? 'INR';

            $rec = PatentInvoiceIn::create($data);

            AuditLog::create([
                'user_id'      => $user->id,
                'action'       => 'create_patent_' . $v['type'],
                'subject_type' => 'PatentInvoiceIn',
                'subject_id'   => $rec->id,
                'metadata'     => ['uin' => $rec->invoice_uin, 'amount' => $rec->invoice_amount],
                'ip_address'   => request()->ip(),
                'user_agent'   => request()->userAgent(),
            ]);

            return $rec;
        });

        return response()->json($record->load(['project:id,project_code,docket_number', 'client:id,company_name,legal_name,client_code']), 201);
    }

    // ── UPDATE ────────────────────────────────────────────────────────────────────

    public function update(Request $request, $id)
    {
        $user   = $request->user();
        $record = PatentInvoiceIn::findOrFail($id);

        abort_unless(\App\Support\DataAccess::canActOnFinance($user) || $user->isGalvanizer(), 403, 'Only Accounts, a Director, a System Admin or someone on the Financial access list can change invoices.');
        if ($user->isGalvanizer() && ! $this->galvanizerCanAccessRecord($user, $record)) abort(403);

        $v = $request->validate([
            'type'                             => 'sometimes|in:invoice,quote',
            'status'                           => 'sometimes|string|max:30',
            'docket_number'                    => 'sometimes|string|max:70',
            'invoice_date'                     => 'sometimes|date',
            'tax_invoice_date'                 => 'nullable|date',
            'tax_serial_number'                => 'nullable|string|max:60',
            'client_code_prefix'               => 'nullable|string|max:10',
            'invention_number'                 => 'nullable|string|max:10',
            'patent_office_code'               => 'nullable|string|max:10',
            'first_inventor_name'              => 'nullable|string|max:255',
            'invention_title'                  => 'nullable|string|max:500',
            'service_code'                     => 'nullable|string|max:20',
            'client_name'                      => 'nullable|string|max:255',
            'client_reference'                 => 'nullable|string|max:50',
            'state_of_supply'                  => 'nullable|string|max:100',
            'entity_status'                    => 'nullable|string|max:150',
            'patent_office_application_number' => 'nullable|string|max:150',
            'additional_information'           => 'nullable|string',
            'patent_office_acknowledgement'    => 'nullable|string',
            'remarks'                          => 'nullable|string',
            'uin_old'                          => 'nullable|string|max:80',
            'uin_old_2'                        => 'nullable|string|max:80',
            'patent_office_fees'               => 'sometimes|numeric|min:0',
            'service_fees'                     => 'sometimes|numeric|min:0',
            'discount_percentage'              => 'nullable|numeric|min:0|max:100',
            'other_expenses'                   => 'sometimes|numeric|min:0',
            'attorney_fees'                    => 'nullable|numeric|min:0',
            'consultant_fees'                  => 'nullable|numeric|min:0',
            'referral_fees'                    => 'nullable|numeric|min:0',
        ]);
        // Month close: neither the current nor the new invoice date may be in a locked month.
        \App\Support\FinancePeriod::assertOpen($record->invoice_date, $v['invoice_date'] ?? null);

        // Imported from Zoho: Zoho owns the amounts, dates and status (re-imported on every
        // sync); the portal keeps its own fields (internal fees, remarks, references).
        if ($record->isFromZoho()) {
            $v = array_diff_key($v, array_flip(PatentInvoiceIn::ZOHO_OWNED));
            if ($v === []) {
                return response()->json(['message' => 'Amounts, dates and status of a Zoho record are changed in Zoho Books; they update here automatically.'], 422);
            }
        }

        DB::transaction(function () use ($v, $record, $user) {
            if ($record->isFromZoho()) {
                // Internal fees change net revenue only; the Zoho amounts stay as imported.
                $internal = array_merge($record->only(['attorney_fees', 'consultant_fees', 'referral_fees']), array_intersect_key($v, array_flip(['attorney_fees', 'consultant_fees', 'referral_fees'])));
                $v['net_revenue'] = round((float) ($record->total_bcy ?? $record->invoice_amount) - array_sum(array_map('floatval', $internal)), 2);
                $record->update($v);
            } else {
                // Merge with current values for recomputation
                $merged = array_merge($record->toArray(), $v);
                $data   = $this->computeTotals($merged);
                $record->update($data);
            }

            AuditLog::create([
                'user_id'      => $user->id,
                'action'       => 'update_patent_' . $record->type,
                'subject_type' => 'PatentInvoiceIn',
                'subject_id'   => $record->id,
                'metadata'     => ['uin' => $record->invoice_uin],
                'ip_address'   => request()->ip(),
                'user_agent'   => request()->userAgent(),
            ]);
        });

        return response()->json($record->fresh(['project:id,project_code,docket_number', 'client:id,company_name,legal_name,client_code']));
    }

    // ── DELETE (cancel) ──────────────────────────────────────────────────────────

    public function destroy(Request $request, $id)
    {
        $user   = $request->user();
        $record = PatentInvoiceIn::findOrFail($id);

        abort_unless(\App\Support\DataAccess::canActOnFinance($user) || $user->isGalvanizer(), 403);
        if ($user->isGalvanizer() && ! $this->galvanizerCanAccessRecord($user, $record)) abort(403);
        if ($record->isFromZoho()) abort(422, 'This record comes from Zoho Books: void or delete it in Zoho and it is cancelled here automatically.');
        \App\Support\FinancePeriod::assertOpen($record->invoice_date);

        $record->update(['status' => 'Cancelled']);

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'cancel_patent_' . $record->type,
            'subject_type' => 'PatentInvoiceIn',
            'subject_id'   => $record->id,
            'metadata'     => ['uin' => $record->invoice_uin],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Record cancelled.']);
    }

    // ── BATCH STATUS UPDATE ───────────────────────────────────────────────────────

    public function batch(Request $request)
    {
        $user = $request->user();
        abort_unless(\App\Support\DataAccess::canActOnFinance($user) || $user->isGalvanizer(), 403, 'Only Accounts, a Director, a System Admin or someone on the Financial access list can change invoices.');

        $v = $request->validate([
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'integer|exists:patent_invoices_in,id',
            'action' => 'required|string|in:mark_sent,mark_accepted,mark_rejected,cancel',
        ]);

        $statusMap = [
            'mark_sent'     => 'Sent',
            'mark_accepted' => 'Accepted',
            'mark_rejected' => 'Rejected',
            'cancel'        => 'Cancelled',
        ];
        $newStatus = $statusMap[$v['action']];
        $updated   = 0;

        DB::transaction(function () use ($v, $user, $newStatus, &$updated) {
            $recordsQuery = PatentInvoiceIn::whereIn('id', $v['ids']);
            if ($user->isGalvanizer()) {
                $recordsQuery->whereHas('project', fn ($project) => $user->applyProjectScope($project));
            }
            $records = $recordsQuery->lockForUpdate()->get();
            foreach ($records as $record) {
                if ($record->status === 'Cancelled' || $record->isFromZoho()) continue;   // Zoho owns a Zoho record's status
                $record->update(['status' => $newStatus]);
                AuditLog::create([
                    'user_id'      => $user->id,
                    'action'       => 'batch_' . $v['action'],
                    'subject_type' => 'PatentInvoiceIn',
                    'subject_id'   => $record->id,
                    'metadata'     => ['uin' => $record->invoice_uin, 'new_status' => $newStatus],
                    'ip_address'   => request()->ip(),
                    'user_agent'   => request()->userAgent(),
                ]);
                $updated++;
            }
        });

        return response()->json(['updated' => $updated]);
    }

    // ── CONVERT QUOTE → INVOICE ───────────────────────────────────────────────────

    public function convert(Request $request, $id)
    {
        $user   = $request->user();
        $record = PatentInvoiceIn::findOrFail($id);

        abort_unless(\App\Support\DataAccess::canActOnFinance($user) || $user->isGalvanizer(), 403, 'Only Accounts, a Director, a System Admin or someone on the Financial access list can change invoices.');
        if ($user->isGalvanizer() && ! $this->galvanizerCanAccessRecord($user, $record)) abort(403);
        if ($record->type !== 'quote') abort(422, 'Only quotations can be converted to invoices.');
        if ($record->isFromZoho()) abort(422, 'Convert this quote to an invoice in Zoho Books; the invoice is imported here automatically.');
        if ($record->status === 'Cancelled') abort(422, 'Cannot convert a cancelled quotation.');
        abort_if($record->converted_invoice_id, 422, 'This quote is already an invoice.');
        // Quote-first billing: convert once the client has paid (or give a reason).
        \App\Services\QuoteFirst::check($record->client_id, $record, $request->input('override_reason'), $user, 'convert quote');

        $invoice = DB::transaction(function () use ($record, $user) {
            $record = PatentInvoiceIn::whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_if(\App\Models\ZohoQuoteExport::where('source_type', 'in')->where('source_id', $record->id)->where('state', '!=', 'rejected')->exists(), 422,
                'This quote is handled in Zoho Books. The accountant must create the invoice there.');
            $record->update(['status' => 'Accepted']);

            $data = $record->toArray();
            foreach (['id', 'created_at', 'updated_at', 'invoice_uin', 'status',
                      'tax_invoice_date', 'tax_serial_number'] as $k) {
                unset($data[$k]);
            }
            $data['type']           = 'invoice';
            $data['status']         = 'Draft';
            $data['invoice_date']   = now()->toDateString();
            $data['created_by_id']  = $user->id;
            $data['invoice_uin']    = $this->computeUin($record->docket_number, 'invoice');

            $inv = PatentInvoiceIn::create($data);
            // Money received on the quote now settles the invoice.
            $moved = \App\Models\RegisterPayment::where('patent_invoice_in_id', $record->id)->get();
            if ($moved->isNotEmpty()) {
                \App\Models\RegisterPayment::whereIn('id', $moved->pluck('id'))->update(['patent_invoice_in_id' => $inv->id]);
                $left = round(max(0, (float) $inv->invoice_amount - (float) $moved->sum('amount')), 2);
                $inv->update(['balance_due' => $left, 'payment_status' => $left <= 0.009 ? 'Confirmed' : 'Partially Paid']);
            }
            $record->update(['converted_invoice_id' => $inv->id]);

            AuditLog::create([
                'user_id'      => $user->id,
                'action'       => 'convert_quote_to_invoice',
                'subject_type' => 'PatentInvoiceIn',
                'subject_id'   => $inv->id,
                'metadata'     => ['from_quote_id' => $record->id, 'uin' => $inv->invoice_uin],
                'ip_address'   => request()->ip(),
                'user_agent'   => request()->userAgent(),
            ]);

            return $inv;
        });

        return response()->json(
            $invoice->load(['project:id,project_code,docket_number', 'client:id,company_name,legal_name,client_code']),
            201
        );
    }

    private function galvanizerCanAccessRecord($user, PatentInvoiceIn $record): bool
    {
        $project = $record->project ?: Project::find($record->project_id);

        return $project && $user->canAccessCircle($project->circle);
    }
}
