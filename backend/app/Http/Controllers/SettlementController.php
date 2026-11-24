<?php

namespace App\Http\Controllers;

use App\Jobs\ZohoSettlementWriteback;
use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use App\Services\InvoiceSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pending Payments actions: mark paid, request / decide a waiver, retry the Zoho write-back. */
class SettlementController extends Controller
{
    public function __construct(private readonly InvoiceSettlement $settlement) {}

    public function markPaid(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|date|before_or_equal:today',
            'mode' => 'required|in:'.implode(',', InvoiceSettlement::MODES),
            'reference' => 'nullable|string|max:120',
        ]);
        $invoice = $this->invoice($request, $id);
        $payment = $this->settlement->markPaid($invoice, (float) $data['amount'], $data['date'], $data['mode'], $data['reference'] ?? null,
            $request->user(), $request->ip(), $request->userAgent());

        return response()->json(['ok' => true, 'payment_id' => $payment->id, 'invoice' => $invoice->fresh()->only(['id', 'balance_due', 'payment_status', 'settlement', 'zoho_writeback'])]);
    }

    public function requestWaiver(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'reason' => 'required|string|max:1000']);
        $invoice = $this->invoice($request, $id);
        $this->settlement->requestWaiver($invoice, (float) $data['amount'], $data['reason'], $request->user(), $request->ip(), $request->userAgent());

        return response()->json(['ok' => true]);
    }

    public function decideWaiver(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['approve' => 'required|boolean', 'note' => 'nullable|string|max:500']);
        $invoice = $this->invoice($request, $id);
        $this->settlement->decideWaiver($invoice, (bool) $data['approve'], $request->user(), $data['note'] ?? null, $request->ip(), $request->userAgent());

        return response()->json(['ok' => true, 'invoice' => $invoice->fresh()->only(['id', 'balance_due', 'payment_status', 'settlement', 'zoho_writeback'])]);
    }

    /** Try the Zoho write-back again after a failure. */
    public function retryZoho(Request $request, int $id): JsonResponse
    {
        abort_unless(in_array($request->user()->role, InvoiceSettlement::PAY_ROLES, true), 403);
        $invoice = $this->invoice($request, $id);
        abort_unless($invoice->zoho_writeback === 'failed', 422, 'Nothing to retry.');
        $pending = RegisterPayment::where('patent_invoice_in_id', $invoice->id)->where('source', 'portal')->where('zoho_payment_id', 'like', 'portal:%')->latest('id')->first();
        $invoice->update(['zoho_writeback' => 'pending', 'zoho_writeback_error' => null]);
        if ($pending) {
            ZohoSettlementWriteback::dispatch($invoice->id, 'payment', $pending->id);
        } elseif ($invoice->settlement === 'waived') {
            ZohoSettlementWriteback::dispatch($invoice->id, 'writeoff');
        } else {
            $invoice->update(['zoho_writeback' => 'done']);
        }

        return response()->json(['ok' => true]);
    }

    /** Staff only, and galvanizers only within their circle. */
    private function invoice(Request $request, int $id): PatentInvoiceIn
    {
        $user = $request->user();
        abort_if($user->isClientRole() || $user->isInventor(), 403);
        $invoice = PatentInvoiceIn::findOrFail($id);
        if ($user->isGalvanizer()) {
            $scope = \App\Models\Project::whereKey($invoice->project_id);
            $user->applyProjectScope($scope);
            abort_unless($invoice->project_id && $scope->exists(), 403);
        }

        return $invoice;
    }
}
