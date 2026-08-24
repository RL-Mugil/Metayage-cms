<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\ClientLedger;
use App\Models\Quotation;
use App\Models\Client;
use App\Models\AuditLog;
use App\Models\Project;
use App\Http\PaginationHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;

class FinancialController extends Controller
{
    /**
     * Compute GST breakdown based on client's location.
     * Karnataka client  → CGST 9% + SGST 9% (intra-state)
     * Other Indian      → IGST 18% (inter-state)
     * Export client     → 0% (zero-rated)
     */
    private function computeGst(Client $client, float $subtotal): array
    {
        if ($client->gst_type === 'Export' || strtolower($client->nationality ?? 'india') !== 'india') {
            return ['tax_rate' => 0, 'tax_amount' => 0.0, 'tax_details' => null];
        }

        $isKarnataka = strtolower(trim($client->state ?? '')) === 'karnataka';
        $taxAmount   = round($subtotal * 0.18, 2);

        if ($isKarnataka) {
            return [
                'tax_rate'    => 18,
                'tax_amount'  => $taxAmount,
                'tax_details' => [
                    'type'        => 'CGST+SGST',
                    'cgst_rate'   => 9,
                    'cgst_amount' => round($subtotal * 0.09, 2),
                    'sgst_rate'   => 9,
                    'sgst_amount' => round($subtotal * 0.09, 2),
                    'igst_rate'   => null,
                    'igst_amount' => null,
                ],
            ];
        }

        return [
            'tax_rate'    => 18,
            'tax_amount'  => $taxAmount,
            'tax_details' => [
                'type'        => 'IGST',
                'cgst_rate'   => null,
                'cgst_amount' => null,
                'sgst_rate'   => null,
                'sgst_amount' => null,
                'igst_rate'   => 18,
                'igst_amount' => $taxAmount,
            ],
        ];
    }

    public function inertiaIndex(Request $request)
    {
        // Must match the file's exact casing ('Financial.tsx') — the lowercase
        // page name resolved to a stale orphan file (resources/js/pages/financial.tsx,
        // dated Jul 18, never touched since) on the server's case-sensitive
        // filesystem, which silently masked every edit made to Financial.tsx
        // in production despite successful deploys. See PR/commit notes.
        return Inertia::render('Financial');
    }

    public function stats(Request $request)
    {
        $user = $request->user();
        $this->authorize('viewAny', \App\Models\Invoice::class);
        $base = Invoice::query();

        if ($user->isClientRole()) {
            $base->whereHas('client', function ($q) use ($user) {
                $q->visibleToUser($user);
            });
        } elseif ($user->isGalvanizer()) {
            $base->whereHas('project', fn ($q) => $user->applyProjectScope($q));
        }

        $cacheKey = "financial_stats_{$user->id}_{$user->role}_v" . Cache::get('dashboard_v', 0);
        $stats = Cache::remember($cacheKey, 300, function () use ($base) {
            $row = (clone $base)->selectRaw("
                COALESCE(SUM(total_amount), 0) as total_billed,
                COALESCE(SUM(CASE WHEN status = 'Paid' THEN total_amount ELSE 0 END), 0) as total_received,
                COALESCE(SUM(CASE WHEN status IN ('Sent', 'Overdue', 'Partially Paid') THEN balance_due ELSE 0 END), 0) as total_outstanding,
                SUM(CASE WHEN status = 'Overdue' THEN 1 ELSE 0 END) as overdue_count,
                SUM(CASE WHEN status = 'Draft' THEN 1 ELSE 0 END) as draft_count,
                SUM(CASE WHEN status = 'Paid' THEN 1 ELSE 0 END) as paid_count
            ")->first();
            return [
                'total_billed' => (float) ($row?->total_billed ?? 0),
                'total_received' => (float) ($row?->total_received ?? 0),
                'total_outstanding' => (float) ($row?->total_outstanding ?? 0),
                'overdue_count' => (int) ($row?->overdue_count ?? 0),
                'draft_count' => (int) ($row?->draft_count ?? 0),
                'paid_count' => (int) ($row?->paid_count ?? 0),
            ];
        });

        return response()->json($stats);
    }

    public function invoices(Request $request)
    {
        $user = $request->user();
        $this->authorize('viewAny', \App\Models\Invoice::class);
        $query = Invoice::with('client', 'project');

        if ($user->isClientRole()) {
            $query->whereHas('client', function ($q) use ($user) {
                $q->visibleToUser($user);
            });
        } elseif ($user->isGalvanizer()) {
            $query->whereHas('project', fn ($q) => $user->applyProjectScope($q));
        } elseif ($user->role === 'associate') {
            $query->whereHas('project', fn ($q) => $q->where($this->analystProjectScope($user)));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->boolean('outstanding')) {
            $query->whereIn('status', ['Sent', 'Overdue', 'Partially Paid']);
        }

        return response()->json(PaginationHelper::paginate($query->orderBy('issue_date', 'desc'), $request));
    }

    public function showInvoice(Request $request, $id)
    {
        $invoice = Invoice::with(['client', 'project', 'items', 'payments'])->findOrFail($id);
        $this->authorize('view', $invoice);
        return response()->json($invoice);
    }

    public function quotations(Request $request)
    {
        $user = $request->user();
        $this->authorize('viewAny', \App\Models\Invoice::class);
        $query = Quotation::with('client', 'project');

        if ($user->isClientRole()) {
            $query->whereHas('client', function ($q) use ($user) {
                $q->visibleToUser($user);
            });
        } elseif ($user->isGalvanizer()) {
            $query->whereHas('project', fn ($q) => $user->applyProjectScope($q));
        } elseif ($user->role === 'associate') {
            $query->whereHas('project', fn ($q) => $q->where($this->analystProjectScope($user)));
        }

        return response()->json(PaginationHelper::paginate($query->orderBy('created_at', 'desc'), $request));
    }

    public function createInvoice(Request $request)
    {
        $user = $request->user();
        $this->authorize('create', \App\Models\Invoice::class);

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'project_id' => 'nullable|exists:projects,id',
            'due_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.amount' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:5',
            'payment_terms' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $project = $this->resolveAuthorizedProject($user, $validated['project_id'] ?? null);
        if ($user->role === 'associate' && !$project) {
            return response()->json(['message' => 'Patent analysts must raise invoices against an assigned case.'], 422);
        }
        if ($user->isGalvanizer() && !$project) {
            return response()->json(['message' => 'Galvanizers must raise invoices against a case in their assigned circle.'], 422);
        }

        if ($project && (int) $project->client_id !== (int) $validated['client_id']) {
            return response()->json(['message' => 'Selected case does not belong to the selected client.'], 422);
        }

        $client   = Client::findOrFail($validated['client_id']);
        $subtotal = collect($validated['items'])->sum('amount');
        $gst      = $this->computeGst($client, $subtotal);
        $taxRate  = $gst['tax_rate'];
        $taxAmount   = $gst['tax_amount'];
        $taxDetails  = $gst['tax_details'];
        $totalAmount = $subtotal + $taxAmount;

        $invoice = \DB::transaction(function () use ($validated, $subtotal, $taxAmount, $taxRate, $taxDetails, $totalAmount) {
            // Redis atomic counter: O(1) vs locking the entire invoices table.
            // On first use (or after Redis restart) we seed from the DB max.
            $year = date('Y');
            $redisKey = "seq:invoice:{$year}";
            if (!Redis::exists($redisKey)) {
                $last = Invoice::where('invoice_code', 'like', "INV-{$year}-%")
                    ->orderBy('invoice_code', 'desc')->value('invoice_code');
                Redis::setnx($redisKey, $last ? (int) substr($last, -5) : 0);
            }
            $seq = Redis::incr($redisKey);
            $code = sprintf('INV-%s-%05d', $year, $seq);

            $invoice = Invoice::create([
                'invoice_code' => $code,
                'client_id' => $validated['client_id'],
                'project_id' => $validated['project_id'] ?? null,
                'issue_date' => now()->toDateString(),
                'due_date' => $validated['due_date'],
                'currency' => $validated['currency'] ?? 'INR',
                'subtotal'      => $subtotal,
                'tax_amount'    => $taxAmount,
                'tax_details'   => $taxDetails,
                'total_amount'  => $totalAmount,
                'balance_due'   => $totalAmount,
                'payment_terms' => $validated['payment_terms'] ?? 'Net 30',
                'status'        => 'Draft',
            ]);

            foreach ($validated['items'] as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => 1,
                    'unit_rate' => $item['amount'],
                    'amount' => $item['amount'],
                    'tax_rate' => $taxRate,
                ]);
            }

            // Running balance continues from the client's latest ledger row.
            $latestLedger = ClientLedger::where('client_id', $validated['client_id'])
                ->orderBy('id', 'desc')->lockForUpdate()->first();
            $runningBalance = ($latestLedger ? $latestLedger->balance : 0.00) + $totalAmount;

            ClientLedger::create([
                'client_id' => $validated['client_id'],
                'transaction_date' => now()->toDateString(),
                'document_type' => 'Invoice',
                'document_reference' => $code,
                'debit' => $totalAmount,
                'credit' => 0,
                'balance' => $runningBalance,
                'notes' => $validated['notes'] ?? null,
            ]);

            return $invoice;
        });

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'create_invoice',
            'subject_type' => 'Invoice',
            'subject_id' => $invoice->id,
            'metadata' => ['code' => $invoice->invoice_code, 'total' => $totalAmount],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        Cache::increment('dashboard_v');
        return response()->json($invoice->load('client'), 201);
    }

    public function updateInvoice(Request $request, $id)
    {
        $user = $request->user();
        $invoice = Invoice::findOrFail($id);
        $this->authorize('update', $invoice);
        $validated = $request->validate([
            'status' => 'sometimes|in:Draft,Pending Approval,Sent,Viewed,Partially Paid,Paid,Overdue,Cancelled',
            'due_date' => 'sometimes|date',
            'payment_terms' => 'sometimes|string',
        ]);

        // Route cancellations through the dedicated transaction so the ledger is reversed correctly.
        if (($validated['status'] ?? null) === 'Cancelled') {
            return $this->deleteInvoice($request, $id);
        }

        $invoice->update($validated);
        Cache::increment('dashboard_v');
        return response()->json($invoice->load('client'));
    }

    public function deleteInvoice(Request $request, $id)
    {
        $user = $request->user();
        $invoiceCheck = Invoice::findOrFail($id);
        $this->authorize('delete', $invoiceCheck);

        \DB::transaction(function () use ($id, $user, $request) {
            $invoice = Invoice::lockForUpdate()->findOrFail($id);

            if ($invoice->status === 'Cancelled') {
                return; // already cancelled, nothing to reverse
            }

            $invoice->update(['status' => 'Cancelled']);

            // Reverse only the remaining outstanding balance (balance_due).
            // Payments already applied have their own credit ledger entries; reversing
            // total_amount here would double-credit the already-paid portion.
            $amountToReverse = (float) $invoice->balance_due;
            $latestLedger = ClientLedger::where('client_id', $invoice->client_id)
                ->orderBy('id', 'desc')->lockForUpdate()->first();
            $runningBalance = ($latestLedger ? $latestLedger->balance : 0.00) - $amountToReverse;

            ClientLedger::create([
                'client_id' => $invoice->client_id,
                'transaction_date' => now()->toDateString(),
                'document_type' => 'Credit Note',
                'document_reference' => $invoice->invoice_code,
                'debit' => 0,
                'credit' => $amountToReverse,
                'balance' => $runningBalance,
                'notes' => 'Invoice cancelled',
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'cancel_invoice',
                'subject_type' => 'Invoice',
                'subject_id' => $invoice->id,
                'metadata' => ['invoice_code' => $invoice->invoice_code],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        Cache::increment('dashboard_v');
        return response()->json(['message' => 'Invoice cancelled']);
    }

    public function storeQuotation(Request $request)
    {
        $user = $request->user();
        $this->authorize('create', \App\Models\Invoice::class);

        $validated = $request->validate([
            'client_id'                => 'required|exists:clients,id',
            'project_id'               => 'nullable|exists:projects,id',
            'valid_until'              => 'required|date',
            'fee_structure'            => 'required|in:Fixed Fee,Hourly,Blended',
            'estimated_hours'          => 'nullable|numeric|min:0',
            'hourly_rates'             => 'nullable|array',
            'estimated_disbursements'  => 'nullable|numeric|min:0',
            'buffer_percentage'        => 'nullable|numeric|min:0|max:100',
            'total_amount'             => 'required|numeric|min:0',
            'currency'                 => 'nullable|string|max:5',
        ]);

        $project = $this->resolveAuthorizedProject($user, $validated['project_id'] ?? null);
        if ($user->role === 'associate' && !$project) {
            return response()->json(['message' => 'Patent analysts must raise quotations against an assigned case.'], 422);
        }
        if ($user->isGalvanizer() && !$project) {
            return response()->json(['message' => 'Galvanizers must raise quotations against a case in their assigned circle.'], 422);
        }

        if ($project && (int) $project->client_id !== (int) $validated['client_id']) {
            return response()->json(['message' => 'Selected case does not belong to the selected client.'], 422);
        }

        $client   = Client::findOrFail($validated['client_id']);
        $subtotal = (float) $validated['total_amount']; // user enters pre-tax fee amount
        $gst      = $this->computeGst($client, $subtotal);

        $quotation = \DB::transaction(function () use ($validated, $subtotal, $gst) {
            $year    = date('Y');
            $redisKey = "seq:quotation:{$year}";
            if (!Redis::exists($redisKey)) {
                $last = Quotation::where('quote_code', 'like', "QUO-{$year}-%")
                    ->orderBy('quote_code', 'desc')->value('quote_code');
                Redis::setnx($redisKey, $last ? (int) substr($last, -5) : 0);
            }
            $seq  = Redis::incr($redisKey);
            $code = sprintf('QUO-%s-%05d', $year, $seq);

            return Quotation::create([
                'quote_code'              => $code,
                'client_id'               => $validated['client_id'],
                'project_id'              => $validated['project_id'] ?? null,
                'valid_until'             => $validated['valid_until'],
                'fee_structure'           => $validated['fee_structure'],
                'estimated_hours'         => $validated['estimated_hours'] ?? 0,
                'hourly_rates'            => $validated['hourly_rates'] ?? null,
                'estimated_disbursements' => $validated['estimated_disbursements'] ?? 0,
                'buffer_percentage'       => $validated['buffer_percentage'] ?? 0,
                'subtotal'                => $subtotal,
                'tax_amount'              => $gst['tax_amount'],
                'tax_details'             => $gst['tax_details'],
                'total_amount'            => $subtotal + $gst['tax_amount'],
                'currency'                => $validated['currency'] ?? 'INR',
                'status'                  => 'Draft',
            ]);
        });

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'create_quotation',
            'subject_type' => 'Quotation',
            'subject_id'   => $quotation->id,
            'metadata'     => ['code' => $quotation->quote_code, 'total' => $validated['total_amount']],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        Cache::increment('dashboard_v');
        return response()->json($quotation->load('client'), 201);
    }

    public function updateQuotation(Request $request, $id)
    {
        $user      = $request->user();
        $this->authorize('create', \App\Models\Invoice::class);
        $quotation = Quotation::findOrFail($id);
        // Same ownership scoping as storeQuotation() — resolveAuthorizedProject()
        // throws 403 via ProjectPolicy::view() if the quotation's project exists
        // but isn't one this user (associate/galvanizer/etc.) may view; these two
        // checks then require associates/galvanizers to have a project at all,
        // closing the gap where an associate could otherwise update/cancel/convert
        // any client's quotation, not just their own assigned cases.
        $project = $this->resolveAuthorizedProject($user, $quotation->project_id);
        if ($user->role === 'associate' && !$project) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($user->isGalvanizer() && !$project) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'status'                   => 'sometimes|in:Draft,Internal Pending,Sent,Accepted,Expired,Cancelled',
            'valid_until'              => 'sometimes|date',
            'fee_structure'            => 'sometimes|in:Fixed Fee,Hourly,Blended',
            'estimated_hours'          => 'sometimes|numeric|min:0',
            'estimated_disbursements'  => 'sometimes|numeric|min:0',
            'buffer_percentage'        => 'sometimes|numeric|min:0|max:100',
            'total_amount'             => 'sometimes|numeric|min:0',
        ]);

        $quotation->update($validated);

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'update_quotation',
            'subject_type' => 'Quotation',
            'subject_id'   => $quotation->id,
            'metadata'     => $validated,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json($quotation->load('client'));
    }

    public function deleteQuotation(Request $request, $id)
    {
        $user      = $request->user();
        $this->authorize('create', \App\Models\Invoice::class);
        $quotation = Quotation::findOrFail($id);
        // See updateQuotation() — same ownership scoping, closes the same gap.
        $project = $this->resolveAuthorizedProject($user, $quotation->project_id);
        if ($user->role === 'associate' && !$project) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($user->isGalvanizer() && !$project) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $quotation->update(['status' => 'Cancelled']);

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'cancel_quotation',
            'subject_type' => 'Quotation',
            'subject_id'   => $quotation->id,
            'metadata'     => ['quote_code' => $quotation->quote_code],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Quotation cancelled']);
    }

    public function convertToInvoice(Request $request, $id)
    {
        $user      = $request->user();
        $this->authorize('create', \App\Models\Invoice::class);
        $quotation = Quotation::with('client')->findOrFail($id);
        // See updateQuotation() — same ownership scoping, closes the same gap.
        $project = $this->resolveAuthorizedProject($user, $quotation->project_id);
        if ($user->role === 'associate' && !$project) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($user->isGalvanizer() && !$project) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!in_array($quotation->status, ['Sent', 'Accepted'])) {
            return response()->json(['message' => 'Only Sent or Accepted quotations can be converted.'], 422);
        }

        $client      = Client::findOrFail($quotation->client_id);
        $subtotal    = (float) $quotation->subtotal ?: (float) $quotation->total_amount;
        $gst         = $this->computeGst($client, $subtotal);
        $taxRate     = $gst['tax_rate'];
        $taxAmount   = $gst['tax_amount'];
        $taxDetails  = $gst['tax_details'];
        $totalAmount = $subtotal + $taxAmount;

        $invoice = \DB::transaction(function () use ($quotation, $client, $subtotal, $taxAmount, $taxRate, $taxDetails, $totalAmount) {
            $year     = date('Y');
            $redisKey = "seq:invoice:{$year}";
            if (!Redis::exists($redisKey)) {
                $last = Invoice::where('invoice_code', 'like', "INV-{$year}-%")
                    ->orderBy('invoice_code', 'desc')->value('invoice_code');
                Redis::setnx($redisKey, $last ? (int) substr($last, -5) : 0);
            }
            $seq  = Redis::incr($redisKey);
            $code = sprintf('INV-%s-%05d', $year, $seq);

            $invoice = Invoice::create([
                'invoice_code'  => $code,
                'client_id'     => $quotation->client_id,
                'project_id'    => $quotation->project_id,
                'issue_date'    => now()->toDateString(),
                'due_date'      => now()->addDays(30)->toDateString(),
                'currency'      => $quotation->currency,
                'subtotal'      => $subtotal,
                'tax_amount'    => $taxAmount,
                'tax_details'   => $taxDetails,
                'total_amount'  => $totalAmount,
                'balance_due'   => $totalAmount,
                'payment_terms' => 'Net 30',
                'status'        => 'Draft',
            ]);

            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'description' => "Services per Quotation {$quotation->quote_code}",
                'quantity'    => 1,
                'unit_rate'   => $subtotal,
                'amount'      => $subtotal,
                'tax_rate'    => $taxRate,
            ]);

            $latestLedger = ClientLedger::where('client_id', $quotation->client_id)
                ->orderBy('id', 'desc')->lockForUpdate()->first();
            $runningBalance = ($latestLedger ? $latestLedger->balance : 0.00) + $totalAmount;

            ClientLedger::create([
                'client_id'          => $quotation->client_id,
                'transaction_date'   => now()->toDateString(),
                'document_type'      => 'Invoice',
                'document_reference' => $code,
                'debit'              => $totalAmount,
                'credit'             => 0,
                'balance'            => $runningBalance,
                'notes'              => "Converted from {$quotation->quote_code}",
            ]);

            $quotation->update(['status' => 'Accepted']);

            return $invoice;
        });

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'convert_quotation',
            'subject_type' => 'Invoice',
            'subject_id'   => $invoice->id,
            'metadata'     => ['quote_code' => $quotation->quote_code, 'invoice_code' => $invoice->invoice_code],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        Cache::increment('dashboard_v');
        return response()->json($invoice->load('client'), 201);
    }

    private function resolveAuthorizedProject($user, ?int $projectId): ?Project
    {
        if (!$projectId) {
            return null;
        }

        $project = Project::findOrFail($projectId);
        $this->authorize('view', $project);

        return $project;
    }

    private function analystProjectScope($user): \Closure
    {
        return function ($q) use ($user) {
            $q->where('patent_engineer_id', $user->id)
                ->orWhere('assigned_manager_id', $user->id)
                ->orWhere('secondary_manager_id', $user->id)
                ->orWhereHas('tasks', fn ($t) => $t->where('assignee_id', $user->id));
        };
    }

    public function batchUpdate(Request $request)
    {
        $user = $request->user();
        $this->authorize('create', \App\Models\Invoice::class);

        $validated = $request->validate([
            'ids'            => 'required|array|min:1',
            'ids.*'          => 'integer',
            'action'         => 'required|in:mark_sent,mark_paid,cancel',
            'payment_method' => 'required_if:action,mark_paid|nullable|string',
        ]);

        $updated = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($validated['ids'] as $invId) {
            try {
                \DB::transaction(function () use ($invId, $validated, $user, $request, &$updated, &$skipped) {
                    $invoice = Invoice::lockForUpdate()->findOrFail($invId);
                    $this->authorize(
                        $validated['action'] === 'mark_paid' ? 'pay' : ($validated['action'] === 'cancel' ? 'delete' : 'update'),
                        $invoice
                    );

                    if ($validated['action'] === 'mark_sent') {
                        if ($invoice->status !== 'Draft') { $skipped++; return; }
                        $invoice->update(['status' => 'Sent']);
                        AuditLog::create([
                            'user_id' => $user->id, 'action' => 'batch_mark_sent',
                            'subject_type' => 'Invoice', 'subject_id' => $invoice->id,
                            'metadata' => ['invoice_code' => $invoice->invoice_code],
                            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
                        ]);
                        $updated++;

                    } elseif ($validated['action'] === 'mark_paid') {
                        if (!in_array($invoice->status, ['Sent', 'Overdue', 'Partially Paid'])) { $skipped++; return; }

                        $year   = date('Y');
                        $recKey = "seq:receipt:{$year}";
                        if (!Redis::exists($recKey)) {
                            $last = Payment::where('receipt_code', 'like', "REC-{$year}-%")
                                ->orderBy('receipt_code', 'desc')->value('receipt_code');
                            Redis::setnx($recKey, $last ? (int) substr($last, -5) : 0);
                        }
                        $receiptCode = sprintf('REC-%s-%05d', $year, Redis::incr($recKey));

                        Payment::create([
                            'client_id'      => $invoice->client_id,
                            'invoice_id'     => $invoice->id,
                            'receipt_code'   => $receiptCode,
                            'payment_date'   => now()->toDateString(),
                            'amount'         => $invoice->balance_due,
                            'payment_method' => $validated['payment_method'] ?? 'Bank Transfer',
                            'status'         => 'Completed',
                        ]);

                        $latestLedger = ClientLedger::where('client_id', $invoice->client_id)
                            ->orderBy('id', 'desc')->lockForUpdate()->first();
                        $runningBalance = ($latestLedger ? $latestLedger->balance : 0.00) - (float) $invoice->balance_due;

                        ClientLedger::create([
                            'client_id'          => $invoice->client_id,
                            'transaction_date'   => now()->toDateString(),
                            'document_type'      => 'Payment',
                            'document_reference' => $receiptCode,
                            'debit'              => 0,
                            'credit'             => $invoice->balance_due,
                            'balance'            => $runningBalance,
                            'notes'              => 'Batch payment',
                        ]);

                        $invoice->update(['balance_due' => 0, 'status' => 'Paid']);
                        AuditLog::create([
                            'user_id' => $user->id, 'action' => 'batch_mark_paid',
                            'subject_type' => 'Invoice', 'subject_id' => $invoice->id,
                            'metadata' => ['invoice_code' => $invoice->invoice_code],
                            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
                        ]);
                        $updated++;

                    } elseif ($validated['action'] === 'cancel') {
                        if ($invoice->status === 'Cancelled') { $skipped++; return; }

                        $amountToReverse = (float) $invoice->balance_due;
                        $latestLedger = ClientLedger::where('client_id', $invoice->client_id)
                            ->orderBy('id', 'desc')->lockForUpdate()->first();
                        $runningBalance = ($latestLedger ? $latestLedger->balance : 0.00) - $amountToReverse;

                        ClientLedger::create([
                            'client_id'          => $invoice->client_id,
                            'transaction_date'   => now()->toDateString(),
                            'document_type'      => 'Credit Note',
                            'document_reference' => $invoice->invoice_code,
                            'debit'              => 0,
                            'credit'             => $amountToReverse,
                            'balance'            => $runningBalance,
                            'notes'              => 'Batch cancellation',
                        ]);

                        $invoice->update(['status' => 'Cancelled']);
                        AuditLog::create([
                            'user_id' => $user->id, 'action' => 'batch_cancel',
                            'subject_type' => 'Invoice', 'subject_id' => $invoice->id,
                            'metadata' => ['invoice_code' => $invoice->invoice_code],
                            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
                        ]);
                        $updated++;
                    }
                });
            } catch (\Throwable $e) {
                // Log the real exception server-side; never echo raw driver/DB text
                // (constraint/column names etc.) back to the client.
                report($e);
                $errors[] = "Invoice #{$invId}: could not be processed — see server logs.";
            }
        }

        Cache::increment('dashboard_v');
        return response()->json(['updated' => $updated, 'skipped' => $skipped, 'errors' => $errors]);
    }

    public function recordPayment(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'transaction_reference' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $invoiceForAuth = Invoice::findOrFail($validated['invoice_id']);
        $this->authorize('pay', $invoiceForAuth);

        $payment = \DB::transaction(function () use ($validated) {
            // Lock the invoice row so concurrent payments cannot double-apply.
            $invoice = Invoice::lockForUpdate()->findOrFail($validated['invoice_id']);
            $client = Client::findOrFail($invoice->client_id);

            if ($validated['amount'] > (float) $invoice->balance_due) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => ["Payment exceeds outstanding balance of {$invoice->balance_due}."],
                ]);
            }

            // Redis atomic counter for receipt codes.
            $year = date('Y');
            $recKey = "seq:receipt:{$year}";
            if (!Redis::exists($recKey)) {
                $last = Payment::where('receipt_code', 'like', "REC-{$year}-%")
                    ->orderBy('receipt_code', 'desc')->value('receipt_code');
                Redis::setnx($recKey, $last ? (int) substr($last, -5) : 0);
            }
            $receiptCode = sprintf('REC-%s-%05d', $year, Redis::incr($recKey));

            // 1. Create Payment record
            $payment = Payment::create([
                'client_id' => $client->id,
                'invoice_id' => $invoice->id,
                'receipt_code' => $receiptCode,
                'payment_date' => Carbon::now()->toDateString(),
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'transaction_reference' => $validated['transaction_reference'] ?? null,
                'status' => 'Completed',
                'notes' => $validated['notes'] ?? null,
            ]);

            // 2. Update Invoice balance
            $newBalance = round(max(0.00, $invoice->balance_due - $validated['amount']), 2);
            $newStatus = $newBalance <= 0.00 ? 'Paid' : 'Partially Paid';
            $invoice->update([
                'balance_due' => $newBalance,
                'status' => $newStatus,
            ]);

            // 3. Create client ledger record (payment received)
            $latestLedger = ClientLedger::where('client_id', $client->id)
                ->orderBy('id', 'desc')->lockForUpdate()->first();
            $runningBalance = ($latestLedger ? $latestLedger->balance : 0.00) - $validated['amount'];

            ClientLedger::create([
                'client_id' => $client->id,
                'transaction_date' => Carbon::now()->toDateString(),
                'document_type' => 'Payment',
                'document_reference' => $receiptCode,
                'debit' => 0.00,
                'credit' => $validated['amount'],
                'balance' => $runningBalance,
                'notes' => $validated['notes'] ?? null,
            ]);

            return $payment;
        });

        $invoice = Invoice::find($validated['invoice_id']);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'record_payment',
            'subject_type' => 'Payment',
            'subject_id' => $payment->id,
            'metadata' => ['amount' => $payment->amount, 'invoice_code' => $invoice->invoice_code],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        Cache::increment('dashboard_v');
        return response()->json($payment, 201);
    }
}
