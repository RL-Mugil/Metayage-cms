<?php

namespace App\Http\Controllers;

use App\Exceptions\ZohoBudgetExhausted;
use App\Http\Requests\SendZohoQuoteRequest;
use App\Http\Resources\ZohoSyncResource;
use App\Models\ZohoQuoteExport;
use App\Services\ZohoBooksService;
use App\Services\ZohoQuoteDraft;
use App\Services\ZohoQuoteWriter;
use App\Services\ZohoMirrorSync;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ZohoQuoteController extends Controller
{
    public function preview(Request $request, string $kind, int $id, ZohoBooksService $zoho, ZohoQuoteDraft $drafts, ZohoMirrorSync $mirror): ZohoSyncResource
    {
        // Accounts sends; anyone with finance access may preview to ask Accounts for approval.
        $canSend = $request->user()->can('create', ZohoQuoteExport::class);
        abort_unless($canSend || \App\Support\DataAccess::canOpenFinance($request->user()), 403);
        $input = $request->validate(['search' => 'nullable|string|max:100', 'customer_id' => ['nullable', 'string', 'regex:/^[0-9]{1,80}$/'], 'lookup' => 'nullable|boolean']);
        if (! $canSend) {
            $input['lookup'] = false;   // a Zoho customer lookup spends API calls: Accounts only
        }
        $quote = $drafts->source($kind, $id);
        $scope = $zoho->scope();
        $export = ZohoQuoteExport::where('scope', $scope)->where('source_type', $kind)->where('source_id', $id)->first();
        $customerId = (string) ($input['customer_id'] ?? $quote->client?->zoho_contact_id ?? '');
        $preview = null;
        $error = $zoho->isConfigured() ? null : 'Zoho Books is not configured.';
        if ($error === null && ! empty($input['lookup']) && trim((string) ($input['search'] ?? '')) !== '') {
            // A customer created in Zoho after the last nightly scan: read it now (manual allowance, a few calls).
            try {
                $mirror->fetchCustomers((string) $input['search']);
            } catch (ZohoBudgetExhausted $exception) {
                $error = $exception->getMessage();
            } catch (\Illuminate\Http\Exceptions\HttpResponseException|\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                report($exception);
                $error = 'Zoho could not be reached to look up that customer. Try again shortly.';
            }
        }
        if ($customerId !== '' && $error === null) {
            try {
                $preview = $drafts->build($quote, $scope, $customerId);
            } catch (ValidationException $exception) {
                $error = $exception->validator->errors()->first();
            }
        }
        $approval = \App\Models\QuoteSendApproval::where('quote_kind', $kind)->where('quote_id', $id)->whereIn('status', ['pending', 'approved', 'rejected'])->latest('id')->first();
        return new ZohoSyncResource(['state' => $export?->state ?? 'new', 'estimate_id' => $export?->estimate_id,
            'can_send' => $canSend,
            'approval' => $approval ? ['status' => $approval->status, 'current' => $preview !== null && hash_equals($approval->version, $preview['version']), 'note' => $approval->note] : null,
            'invoice_id' => $export?->invoice_id, 'conversion_state' => $export?->conversion_state ?? 'not_requested',
            'customer_id' => $customerId, 'contacts' => $drafts->contacts($scope, $input['search'] ?? ''),
            'preview' => $preview, 'error' => $error]);
    }

    public function send(SendZohoQuoteRequest $request, string $kind, int $id, ZohoQuoteWriter $writer)
    {
        $input = $request->validated();
        try {
            return new ZohoSyncResource($writer->send($kind, $id, $input['customer_id'], $input['version'], $request->user()));
        } catch (ZohoBudgetExhausted $exception) {
            return (new ZohoSyncResource(['message' => $exception->getMessage()]))->response()->setStatusCode(429)
                ->header('Retry-After', (string) $exception->retryAfter);
        }
    }

    /**
     * Ask Accounts to approve this exact draft for Zoho (anyone who can work on finance). Accounts
     * then approves it on Financial Suite → Close & controls and sends it.
     */
    public function requestApproval(Request $request, string $kind, int $id, ZohoBooksService $zoho, ZohoQuoteDraft $drafts): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless(\App\Support\DataAccess::canOpenFinance($user), 403, 'You do not have access to finance.');
        $input = $request->validate(['customer_id' => ['required', 'string', 'regex:/^[0-9]{1,80}$/'], 'version' => 'required|string|size:64', 'note' => 'nullable|string|max:500']);
        $quote = $drafts->source($kind, $id);
        $draft = $drafts->build($quote, $zoho->scope(), $input['customer_id']);
        abort_unless(hash_equals($draft['version'], $input['version']), 409, 'The quotation changed. Review the refreshed draft first.');
        $approval = \Illuminate\Support\Facades\DB::transaction(function () use ($kind, $id, $input, $user) {
            \App\Models\QuoteSendApproval::where('quote_kind', $kind)->where('quote_id', $id)->where('status', 'pending')->update(['status' => 'superseded']);
            $approval = \App\Models\QuoteSendApproval::create(['quote_kind' => $kind, 'quote_id' => $id, 'customer_id' => $input['customer_id'],
                'version' => $input['version'], 'requested_by_id' => $user->id, 'status' => 'pending', 'note' => $input['note'] ?? null]);
            \App\Models\AuditLog::create(['user_id' => $user->id, 'action' => 'quote_approval_requested', 'subject_type' => 'QuoteSendApproval', 'subject_id' => $approval->id,
                'metadata' => ['kind' => $kind, 'quote_id' => $id], 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);

            return $approval;
        });
        $accounts = \App\Models\User::where('status', 'Active')->get()->filter(fn ($u) => \App\Services\InvoiceSettlement::canPay($u) && $u->id !== $user->id)->pluck('id')->all();
        \App\Support\Notifier::push($accounts, 'quote_approval', 'Quote waiting for approval',
            "{$user->name} asks to send ".($quote->invoice_uin ?? $quote->quote_code ?? "quote #{$id}").' to Zoho.', '/financial?tab=controls');

        return response()->json($approval, 201);
    }

    public function deleteInZoho(Request $request, string $kind, int $id, ZohoQuoteWriter $writer): ZohoSyncResource
    {
        $this->authorize('create', ZohoQuoteExport::class);
        return new ZohoSyncResource($writer->deleteInZoho($kind, $id));
    }

    public function convertToInvoice(Request $request, string $kind, int $id, ZohoQuoteWriter $writer, ZohoMirrorSync $mirror): ZohoSyncResource
    {
        $this->authorize('create', ZohoQuoteExport::class);
        $result = $writer->convertToInvoice($kind, $id);
        if (! empty($result['invoice_id'])) {
            try {
                $mirror->syncRemote('invoices', (string) $result['invoice_id']);
            } catch (\Throwable $error) {
                report($error);
                $result['message'] .= ' The invoice was created in Zoho; portal mirroring is queued for the next poll.';
            }
        }
        return new ZohoSyncResource($result);
    }
}
