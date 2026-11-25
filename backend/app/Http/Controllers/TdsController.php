<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Tds26asEntry;
use App\Models\TdsDeductor;
use App\Models\TdsRecord;
use App\Services\Form26asParser;
use App\Services\FinanceFigures;
use App\Services\InvoiceSettlement;
use App\Services\TdsTracker;
use App\Support\DataAccess;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Finance → TDS: tax the clients withheld, whether it shows in the firm's Form 26AS, and the
 * Form 16A certificates received. Viewing follows the user's finance scope; uploading 26AS,
 * mapping deductors and recording 16As is for Accounts.
 */
class TdsController extends Controller
{
    public function __construct(private TdsTracker $tracker) {}

    private function records(Request $request)
    {
        $user = $request->user();
        abort_unless(DataAccess::canOpenFinance($user), 403, 'You do not have access to finance.');
        $query = TdsRecord::query()->with(['client:id,client_code,legal_name,company_name,account_manager_id', 'invoice:id,invoice_uin', 'entry', 'document:id,file_name,storage_path']);
        if (! DataAccess::seesAllFinance($user)) {
            $query->whereIn('patent_invoice_in_id', app(FinanceFigures::class)->registerInvoices($user)->select('id'));
        }

        return $query;
    }

    private function allowAccounts(Request $request): void
    {
        abort_unless(InvoiceSettlement::canPay($request->user()), 403, 'Only Accounts can change the TDS tracker.');
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['fy' => 'nullable|string|max:9', 'quarter' => 'nullable|in:Q1,Q2,Q3,Q4', 'client_id' => 'nullable|integer',
            'status' => 'nullable|in:pending,in_26as,no_16a', 'search' => 'nullable|string|max:100']);
        $base = $this->records($request);
        $fys = (clone $base)->distinct()->orderByDesc('fy')->pluck('fy');
        $query = (clone $base)
            ->when($f['fy'] ?? null, fn ($q, $v) => $q->where('fy', $v))
            ->when($f['quarter'] ?? null, fn ($q, $v) => $q->where('quarter', $v))
            ->when($f['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v));
        $all = (clone $query)->get();
        $rows = $query
            ->when(($f['status'] ?? null) === 'no_16a', fn ($q) => $q->whereNull('form16a_received_on'))
            ->when(in_array($f['status'] ?? null, ['pending', 'in_26as'], true), fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('deducted_on')->get()
            ->filter(function ($r) use ($f) {
                $s = mb_strtolower(trim((string) ($f['search'] ?? '')));

                return $s === '' || str_contains(mb_strtolower(implode(' ', [$r->payment_number, $r->invoice?->invoice_uin, $r->client?->client_code, $r->client?->legal_name])), $s);
            })->values();

        $seesAll = DataAccess::seesAllFinance($request->user());
        $mapped = TdsDeductor::whereNotNull('client_id')->pluck('tan');

        return response()->json([
            'rows' => $rows->map(fn (TdsRecord $r) => [
                'id' => $r->id, 'date' => $r->deducted_on?->toDateString(), 'fy' => $r->fy, 'quarter' => $r->quarter, 'amount' => (float) $r->amount,
                'payment_number' => $r->payment_number, 'invoice' => $r->invoice?->invoice_uin, 'status' => $r->status,
                'client' => $r->client ? ['id' => $r->client->id, 'code' => $r->client->client_code, 'name' => $r->client->legal_name ?: $r->client->company_name] : null,
                'entry' => $r->entry ? ['date' => $r->entry->transaction_date?->toDateString(), 'tds' => (float) $r->entry->tds, 'tan' => $r->entry->tan, 'status' => $r->entry->booking_status] : null,
                'form16a' => $r->form16a_received_on ? ['received_on' => $r->form16a_received_on->toDateString(), 'certificate_no' => $r->form16a_certificate_no,
                    'document' => $r->document ? ['file_name' => $r->document->file_name, 'storage_path' => $r->document->storage_path] : null] : null,
                'note' => $r->note,
            ]),
            'summary' => [
                'withheld' => round((float) $all->sum('amount'), 2), 'count' => $all->count(),
                'in_26as' => round((float) $all->where('status', 'in_26as')->sum('amount'), 2),
                'missing' => round((float) $all->where('status', '!=', 'in_26as')->sum('amount'), 2),
                'no_16a' => $all->whereNull('form16a_received_on')->count(),
            ],
            'fys' => $fys,
            'clients' => $all->pluck('client')->filter()->unique('id')->sortBy('client_code')->map(fn ($c) => ['id' => $c->id, 'code' => $c->client_code, 'name' => $c->legal_name ?: $c->company_name])->values(),
            // Accounts only: 26AS credits that match no TDS line, and deductors not yet tied to a client.
            'unmatched_26as' => $seesAll ? Tds26asEntry::whereNotIn('id', TdsRecord::whereNotNull('tds_26as_entry_id')->select('tds_26as_entry_id'))
                ->orderByDesc('transaction_date')->limit(300)->get()->map(fn ($e) => ['id' => $e->id, 'tan' => $e->tan, 'deductor' => $e->deductor_name,
                    'date' => $e->transaction_date?->toDateString(), 'tds' => (float) $e->tds, 'amount_paid' => (float) $e->amount_paid, 'fy' => $e->fy,
                    'quarter' => $e->quarter, 'mapped' => $mapped->contains($e->tan)]) : [],
            'deductors' => $seesAll ? TdsDeductor::with('client:id,client_code,legal_name')->orderByRaw('client_id IS NOT NULL')->orderBy('name')->get()
                ->map(fn ($d) => ['tan' => $d->tan, 'name' => $d->name, 'client' => $d->client ? ['id' => $d->client->id, 'code' => $d->client->client_code, 'name' => $d->client->legal_name] : null]) : [],
            'uploaded' => $seesAll ? Tds26asEntry::selectRaw('fy, quarter, count(*) n')->groupBy('fy', 'quarter')->orderByDesc('fy')->orderBy('quarter')->get() : [],
            'can' => ['edit' => InvoiceSettlement::canPay($request->user())],
        ]);
    }

    public function upload(Request $request, Form26asParser $parser): JsonResponse
    {
        $this->allowAccounts($request);
        $request->validate(['file' => 'required|file|max:10240|mimes:txt,csv,text']);
        try {
            $entries = $parser->parse($request->file('file')->getRealPath());
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        $result = $this->tracker->import($entries, (string) Str::uuid());
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'tds_26as_uploaded', 'subject_type' => 'Finance', 'subject_id' => 0,
            'metadata' => $result + ['file' => $request->file('file')->getClientOriginalName()], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response()->json($result);
    }

    public function mapDeductor(Request $request, string $tan): JsonResponse
    {
        $this->allowAccounts($request);
        $data = $request->validate(['client_id' => 'nullable|integer|exists:clients,id']);
        $deductor = TdsDeductor::where('tan', strtoupper($tan))->firstOrFail();
        $deductor->update(['client_id' => $data['client_id'] ?? null, 'matched_by' => 'manual']);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'tds_deductor_mapped', 'subject_type' => 'TdsDeductor', 'subject_id' => $deductor->id,
            'metadata' => ['tan' => $deductor->tan, 'client_id' => $deductor->client_id], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response()->json(['matched' => $this->tracker->match()]);
    }

    public function form16a(Request $request, int $id): JsonResponse
    {
        $this->allowAccounts($request);
        $data = $request->validate(['received_on' => 'nullable|date', 'certificate_no' => 'nullable|string|max:60',
            'document_id' => 'nullable|integer|exists:documents,id', 'note' => 'nullable|string|max:500']);
        $record = $this->records($request)->findOrFail($id);
        $record->update(['form16a_received_on' => $data['received_on'] ?? null, 'form16a_certificate_no' => $data['certificate_no'] ?? null,
            'form16a_document_id' => $data['document_id'] ?? null, 'note' => $data['note'] ?? $record->note]);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'tds_16a_recorded', 'subject_type' => 'TdsRecord', 'subject_id' => $record->id,
            'metadata' => array_intersect_key($data, array_flip(['received_on', 'certificate_no', 'document_id'])), 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response()->json(['ok' => true]);
    }

    /** Tell the client's Client Manager which TDS is not yet in 26AS or has no 16A. */
    public function chase(Request $request, int $clientId): JsonResponse
    {
        $this->allowAccounts($request);
        $client = Client::findOrFail($clientId);
        $open = $this->records($request)->where('client_id', $clientId)
            ->where(fn ($q) => $q->where('status', '!=', 'in_26as')->orWhereNull('form16a_received_on'))->get();
        abort_if($open->isEmpty(), 422, 'Nothing to chase for this client.');
        abort_unless($client->account_manager_id, 422, 'This client has no Client Manager.');
        $lines = $open->take(8)->map(fn ($r) => "{$r->fy} {$r->quarter} ₹".number_format((float) $r->amount, 2).' ('.($r->status !== 'in_26as' ? 'not in 26AS' : 'no 16A').')')->implode('; ');
        Notifier::push([$client->account_manager_id], 'tds_chase', "TDS to chase: {$client->client_code}",
            "Ask {$client->legal_name} for the Form 16A / to file the TDS return: {$lines}", '/tds?client_id='.$clientId);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'tds_chase_sent', 'subject_type' => 'Client', 'subject_id' => $clientId,
            'metadata' => ['lines' => $open->count()], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response()->json(['notified' => 1, 'lines' => $open->count()]);
    }
}
