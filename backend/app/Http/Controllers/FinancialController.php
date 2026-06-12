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
use Inertia\Inertia;

class FinancialController extends Controller
{
    public function inertiaIndex(Request $request)
    {
        return Inertia::render('Financial');
    }

    public function invoices(Request $request)
    {
        $user = $request->user();
        $query = Invoice::with('client', 'project');

        // RBAC validation
        if ($user->role === 'client') {
            $query->whereHas('client.contacts', function ($q) use ($user) {
                $q->where('email', $user->email);
            });
        } elseif (! in_array($user->role, ['super_admin', 'partner', 'finance', 'manager'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(PaginationHelper::paginate($query->orderBy('issue_date', 'desc'), $request));
    }

    public function quotations(Request $request)
    {
        $user = $request->user();
        $query = Quotation::with('client', 'project');

        if ($user->role === 'client') {
            $query->whereHas('client.contacts', function ($q) use ($user) {
                $q->where('email', $user->email);
            });
        } elseif (! in_array($user->role, ['super_admin', 'partner', 'finance', 'manager'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(PaginationHelper::paginate($query->orderBy('created_at', 'desc'), $request));
    }

    public function createInvoice(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'partner', 'finance', 'manager'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'client_id'    => 'required|exists:clients,id',
            'project_id'   => 'nullable|exists:projects,id',
            'due_date'     => 'required|date',
            'items'        => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.amount'      => 'required|numeric|min:0',
            'currency'     => 'nullable|string|max:5',
            'payment_terms'=> 'nullable|string',
            'notes'        => 'nullable|string',
        ]);

        $subtotal = collect($validated['items'])->sum('amount');
        $taxAmount = round($subtotal * 0.18, 2);
        $totalAmount = $subtotal + $taxAmount;

        $invoice = \DB::transaction(function () use ($validated, $subtotal, $taxAmount, $totalAmount) {
            // Sequential invoice number from the highest existing code for the
            // year, row-locked so concurrent requests cannot collide.
            $year = date('Y');
            $last = Invoice::where('invoice_code', 'like', "INV-{$year}-%")
                ->orderBy('invoice_code', 'desc')
                ->lockForUpdate()
                ->value('invoice_code');
            $seq  = $last ? ((int) substr($last, -5)) + 1 : 1;
            $code = sprintf('INV-%s-%05d', $year, $seq);

            $invoice = Invoice::create([
                'invoice_code'  => $code,
                'client_id'     => $validated['client_id'],
                'project_id'    => $validated['project_id'] ?? null,
                'issue_date'    => now()->toDateString(),
                'due_date'      => $validated['due_date'],
                'currency'      => $validated['currency'] ?? 'INR',
                'subtotal'      => $subtotal,
                'tax_amount'    => $taxAmount,
                'total_amount'  => $totalAmount,
                'balance_due'   => $totalAmount,
                'payment_terms' => $validated['payment_terms'] ?? 'Net 30',
                'status'        => 'Draft',
            ]);

            foreach ($validated['items'] as $item) {
                InvoiceItem::create([
                    'invoice_id'  => $invoice->id,
                    'description' => $item['description'],
                    'quantity'    => 1,
                    'unit_rate'   => $item['amount'],
                    'amount'      => $item['amount'],
                    'tax_rate'    => 18,
                ]);
            }

            // Running balance continues from the client's latest ledger row.
            $latestLedger = ClientLedger::where('client_id', $validated['client_id'])
                ->orderBy('id', 'desc')->lockForUpdate()->first();
            $runningBalance = ($latestLedger ? $latestLedger->balance : 0.00) + $totalAmount;

            ClientLedger::create([
                'client_id'          => $validated['client_id'],
                'transaction_date'   => now()->toDateString(),
                'document_type'      => 'Invoice',
                'document_reference' => $code,
                'debit'              => $totalAmount,
                'credit'             => 0,
                'balance'            => $runningBalance,
                'notes'              => $validated['notes'] ?? null,
            ]);

            return $invoice;
        });

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'create_invoice',
            'subject_type' => 'Invoice', 'subject_id' => $invoice->id,
            'metadata' => ['code' => $code, 'total' => $totalAmount],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($invoice->load('client'), 201);
    }

    public function updateInvoice(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'partner', 'finance', 'manager'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $invoice = Invoice::findOrFail($id);
        $validated = $request->validate([
            'status'       => 'sometimes|in:Draft,Pending Approval,Sent,Viewed,Partially Paid,Paid,Overdue,Cancelled',
            'due_date'     => 'sometimes|date',
            'payment_terms'=> 'sometimes|string',
        ]);
        $invoice->update($validated);
        return response()->json($invoice->load('client'));
    }

    public function deleteInvoice(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'partner'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $invoice = Invoice::findOrFail($id);
        $invoice->update(['status' => 'Cancelled']);
        return response()->json(['message' => 'Invoice cancelled']);
    }

    public function recordPayment(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'partner', 'finance'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'transaction_reference' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $payment = \DB::transaction(function () use ($request) {
            // Lock the invoice row so concurrent payments cannot double-apply.
            $invoice = Invoice::lockForUpdate()->findOrFail($request->invoice_id);
            $client = Client::findOrFail($invoice->client_id);

            if ($request->amount > (float) $invoice->balance_due) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => ["Payment exceeds outstanding balance of {$invoice->balance_due}."],
                ]);
            }

            // Sequential receipt number, race-safe under the transaction.
            $year = date('Y');
            $last = Payment::where('receipt_code', 'like', "REC-{$year}-%")
                ->orderBy('receipt_code', 'desc')
                ->lockForUpdate()
                ->value('receipt_code');
            $seq         = $last ? ((int) substr($last, -5)) + 1 : 1;
            $receiptCode = sprintf('REC-%s-%05d', $year, $seq);

            // 1. Create Payment record
            $payment = Payment::create([
                'client_id' => $client->id,
                'invoice_id' => $invoice->id,
                'receipt_code' => $receiptCode,
                'payment_date' => Carbon::now()->toDateString(),
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'transaction_reference' => $request->transaction_reference,
                'status' => 'Completed',
                'notes' => $request->notes,
            ]);

            // 2. Update Invoice balance
            $newBalance = round(max(0.00, $invoice->balance_due - $request->amount), 2);
            $newStatus = $newBalance <= 0.00 ? 'Paid' : 'Partially Paid';
            $invoice->update([
                'balance_due' => $newBalance,
                'status' => $newStatus,
            ]);

            // 3. Create client ledger record (payment received)
            $latestLedger = ClientLedger::where('client_id', $client->id)
                ->orderBy('id', 'desc')->lockForUpdate()->first();
            $runningBalance = ($latestLedger ? $latestLedger->balance : 0.00) - $request->amount;

            ClientLedger::create([
                'client_id' => $client->id,
                'transaction_date' => Carbon::now()->toDateString(),
                'document_type' => 'Payment',
                'document_reference' => $receiptCode,
                'debit' => 0.00,
                'credit' => $request->amount,
                'balance' => $runningBalance,
                'notes' => $request->notes,
            ]);

            return $payment;
        });

        $invoice = Invoice::find($request->invoice_id);

        // Audit Log
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'record_payment',
            'subject_type' => 'Payment',
            'subject_id' => $payment->id,
            'metadata' => ['amount' => $payment->amount, 'invoice_code' => $invoice->invoice_code],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json($payment, 201);
    }
}
