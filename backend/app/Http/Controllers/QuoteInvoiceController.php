<?php

namespace App\Http\Controllers;

use App\Models\PatentInvoiceIn;
use App\Services\InvoiceSettlement;
use App\Services\QuoteInvoicing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Quote-first billing: a client's payment arrived → create the tax invoice in Zoho and apply the payment (Accounts). */
class QuoteInvoiceController extends Controller
{
    public function fromPayment(Request $request, int $id, QuoteInvoicing $invoicing): JsonResponse
    {
        abort_unless(InvoiceSettlement::canPay($request->user()), 403, 'Only Accounts can turn a quote into an invoice.');
        $data = $request->validate(['zoho_payment_id' => ['required', 'string', 'regex:/^[0-9]{1,80}$/'], 'override_reason' => 'nullable|string|max:500']);
        \App\Support\FinancePeriod::assertOpen(now());
        $quote = PatentInvoiceIn::where('type', 'quote')->findOrFail($id);
        try {
            return response()->json($invoicing->fromPayment($quote, $data['zoho_payment_id'], $data['override_reason'] ?? null, $request->user()));
        } catch (\App\Exceptions\ZohoBudgetExhausted $e) {
            abort(429, $e->getMessage());
        } catch (\App\Exceptions\ZohoApiException $e) {
            abort(422, 'Zoho refused: HTTP '.$e->status.($e->zohoMessage ? ' - '.$e->zohoMessage : '').'.');
        }
    }
}
