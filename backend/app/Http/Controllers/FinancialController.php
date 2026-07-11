<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\ClientLedger;
use App\Models\Quotation;
use App\Models\Client;
use App\Models\AuditLog;
use App\Http\PaginationHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;

class FinancialController extends Controller
{
    public function inertiaIndex(Request $request)
    {
        return Inertia::render('financial');
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
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->boolean('outstanding')) {
            $query->whereIn('status', ['Sent', 'Overdue', 'Partially Paid']);
        }

        return response()->json(PaginationHelper::paginate($query->orderBy('issue_date', 'desc'), $request));
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

        // Determine applicable GST rate: 0% for Export clients, standard rate otherwise.
        $client = Client::findOrFail($validated['client_id']);
        $taxRate = $client->gst_type === 'Export'
            ? config('services.gst.export_rate', 0)
            : config('services.gst.standard_rate', 18);

        $subtotal = collect($validated['items'])->sum('amount');
        $taxAmount = round($subtotal * ($taxRate / 100), 2);
        $totalAmount = $subtotal + $taxAmount;

        $invoice = \DB::transaction(function () use ($validated, $subtotal, $taxAmount, $taxRate, $totalAmount) {
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
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'balance_due' => $totalAmount,
                'payment_terms' => $validated['payment_terms'] ?? 'Net 30',
                'status' => 'Draft',
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
