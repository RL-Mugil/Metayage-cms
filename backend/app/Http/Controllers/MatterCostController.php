<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Designation;
use App\Models\MatterCost;
use App\Models\Project;
use App\Services\InvoiceSettlement;
use App\Services\MatterProfitability;
use App\Support\DataAccess;
use App\Support\FinancePeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Finance → Costs: official fees, foreign associate bills and other costs paid on matters.
 * Viewing follows the user's finance scope; recording costs is for Accounts and finance staff.
 * Also the internal time cost rates (₹/hour per designation) used for profitability.
 */
class MatterCostController extends Controller
{
    public function __construct(private MatterProfitability $profit) {}

    private function scoped(Request $request)
    {
        $user = $request->user();
        abort_unless(DataAccess::canOpenFinance($user), 403, 'You do not have access to finance.');
        $query = MatterCost::query()->with(['project:id,docket_number,project_name', 'client:id,client_code,legal_name,company_name',
            'billedInvoice:id,invoice_uin', 'document:id,file_name,storage_path', 'createdBy:id,name']);
        if (! DataAccess::seesAllFinance($user)) {
            $query->whereIn('project_id', $this->profit->projects($user)->select('projects.id'));
        }

        return $query;
    }

    private function allowEdit(Request $request): void
    {
        abort_unless(DataAccess::canActOnFinance($request->user()), 403, 'Only Accounts or finance staff can record costs.');
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['kind' => 'nullable|in:official_fee,associate,other', 'project_id' => 'nullable|integer', 'client_id' => 'nullable|integer',
            'from' => 'nullable|date', 'to' => 'nullable|date', 'unassigned' => 'nullable|boolean', 'unbilled' => 'nullable|boolean',
            'review' => 'nullable|boolean', 'search' => 'nullable|string|max:100']);
        $rows = $this->scoped($request)
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($f['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($f['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('bill_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('bill_date', '<=', $v))
            ->when(! empty($f['unassigned']), fn ($q) => $q->whereNull('project_id'))
            ->when(! empty($f['unbilled']), fn ($q) => $q->where('recoverable', true)->where('needs_review', false)->whereNull('billed_invoice_id'))
            ->when(! empty($f['review']), fn ($q) => $q->where('needs_review', true))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('vendor_name', 'ilike', "%{$s}%")->orWhere('vendor_reference', 'ilike', "%{$s}%")
                ->orWhere('description', 'ilike', "%{$s}%")->orWhereHas('project', fn ($p) => $p->where('docket_number', 'ilike', "%{$s}%"))))
            ->orderByDesc('bill_date')->orderByDesc('id')->limit(1000)->get();

        return response()->json([
            'data' => $rows->map(fn (MatterCost $c) => $this->row($c)),
            'totals' => collect(MatterCost::KINDS)->mapWithKeys(fn ($label, $kind) => [$kind => round((float) $rows->where('kind', $kind)->sum('amount_bcy'), 2)]),
            'unassigned' => (clone $this->scoped($request))->whereNull('project_id')->count(),
            'unbilled' => round((float) (clone $this->scoped($request))->where('recoverable', true)->where('needs_review', false)->whereNull('billed_invoice_id')->sum('amount_bcy'), 2),
            'to_review' => (clone $this->scoped($request))->where('needs_review', true)->count(),
            'can' => ['edit' => DataAccess::canActOnFinance($request->user()), 'rates' => InvoiceSettlement::canPay($request->user())],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->allowEdit($request);
        $data = $this->validated($request);
        FinancePeriod::assertOpen($data['bill_date'], $data['paid_on'] ?? null);
        $cost = DB::transaction(function () use ($data, $request) {
            $cost = MatterCost::create($data + ['source' => 'portal', 'created_by_id' => $request->user()->id]);
            $this->audit($request, 'matter_cost_created', $cost);

            return $cost;
        });

        return response()->json($this->row($cost->fresh(['project', 'client', 'billedInvoice', 'document', 'createdBy'])), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->allowEdit($request);
        $cost = $this->scoped($request)->findOrFail($id);
        $data = $this->validated($request, $cost);
        FinancePeriod::assertOpen($cost->bill_date, $cost->paid_on, $data['bill_date'], $data['paid_on'] ?? null);
        if ($cost->source !== 'portal') {
            // Imported from Zoho: amounts stay as Zoho has them; the portal sets the matter, billing and notes.
            $data = array_intersect_key($data, array_flip(['project_id', 'client_id', 'kind', 'recoverable', 'billed_invoice_id', 'notes', 'document_id']));
        }
        DB::transaction(function () use ($cost, $data, $request) {
            $cost->update($data + ['needs_review' => false]);   // saved by a person: reviewed
            $this->audit($request, 'matter_cost_updated', $cost);
        });

        return response()->json($this->row($cost->fresh(['project', 'client', 'billedInvoice', 'document', 'createdBy'])));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->allowEdit($request);
        $cost = $this->scoped($request)->findOrFail($id);
        abort_if($cost->source !== 'portal', 422, 'This cost comes from Zoho Books: delete it there and re-import.');
        FinancePeriod::assertOpen($cost->bill_date, $cost->paid_on);
        DB::transaction(function () use ($cost, $request) {
            $this->audit($request, 'matter_cost_deleted', $cost);
            $cost->delete();
        });

        return response()->json(['ok' => true]);
    }

    /** Confirm an imported cost: recoverable (to be billed to the client) or absorbed by the firm. */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $this->allowEdit($request);
        $cost = $this->scoped($request)->findOrFail($id);
        $recoverable = (bool) $request->validate(['recoverable' => 'required|boolean'])['recoverable'];
        $cost->update(['recoverable' => $recoverable, 'needs_review' => false]);
        $this->audit($request, 'matter_cost_confirmed', $cost);

        return response()->json($this->row($cost->fresh(['project', 'client', 'billedInvoice', 'document', 'createdBy'])));
    }

    /** Internal time cost rates (₹/hour per designation): Accounts only; salaries are never read. */
    public function rates(Request $request): JsonResponse
    {
        abort_unless(InvoiceSettlement::canPay($request->user()), 403);

        return response()->json(MatterProfitability::rates() + ['designation_list' => Designation::orderBy('title')->get(['id', 'title'])]);
    }

    public function saveRates(Request $request): JsonResponse
    {
        abort_unless(InvoiceSettlement::canPay($request->user()), 403);
        $data = $request->validate(['default' => 'required|numeric|min:0|max:100000', 'designations' => 'nullable|array',
            'designations.*' => 'nullable|numeric|min:0|max:100000']);
        $value = ['default' => (float) $data['default'],
            'designations' => collect($data['designations'] ?? [])->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v) => (float) $v)->all()];
        DB::table('system_settings')->updateOrInsert(['key' => MatterProfitability::RATES_KEY], ['value' => json_encode($value), 'updated_at' => now()]);
        MatterProfitability::bump();
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'time_cost_rates_updated', 'subject_type' => 'Finance', 'subject_id' => 0,
            'metadata' => ['designations' => count($value['designations'])], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response()->json($value);
    }

    private function validated(Request $request, ?MatterCost $cost = null): array
    {
        $data = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id', 'kind' => 'required|in:official_fee,associate,other',
            'vendor_name' => 'nullable|string|max:255', 'vendor_reference' => 'nullable|string|max:120', 'description' => 'nullable|string|max:500',
            'bill_date' => 'required|date', 'paid_on' => 'nullable|date', 'currency' => 'required|string|size:3',
            'amount' => 'required|numeric|min:0.01', 'exchange_rate' => 'nullable|numeric|min:0.000001',
            'recoverable' => 'boolean', 'billed_invoice_id' => 'nullable|integer|exists:patent_invoices_in,id',
            'document_id' => 'nullable|integer|exists:documents,id', 'notes' => 'nullable|string|max:500',
        ]);
        $data['currency'] = strtoupper($data['currency']);
        $rate = $data['currency'] === 'INR' ? 1.0 : (float) ($data['exchange_rate'] ?? 0);
        abort_if($rate <= 0, 422, 'Enter the exchange rate to INR for a foreign-currency cost.');
        $data['exchange_rate'] = $rate;
        $data['amount_bcy'] = round((float) $data['amount'] * $rate, 2);
        $data['client_id'] = ! empty($data['project_id']) ? Project::whereKey($data['project_id'])->value('client_id') : $cost?->client_id;
        if (! empty($data['project_id']) && ! DataAccess::seesAllFinance($request->user())) {
            abort_unless($this->profit->projects($request->user())->whereKey($data['project_id'])->exists(), 403, 'That case is not one of yours.');
        }

        return $data;
    }

    private function row(MatterCost $c): array
    {
        return [
            'id' => $c->id, 'kind' => $c->kind, 'kind_label' => MatterCost::KINDS[$c->kind] ?? $c->kind, 'vendor_name' => $c->vendor_name,
            'vendor_reference' => $c->vendor_reference, 'description' => $c->description, 'bill_date' => $c->bill_date?->toDateString(),
            'paid_on' => $c->paid_on?->toDateString(), 'currency' => $c->currency, 'amount' => (float) $c->amount, 'exchange_rate' => (float) $c->exchange_rate,
            'amount_bcy' => (float) $c->amount_bcy, 'recoverable' => $c->recoverable, 'needs_review' => $c->needs_review, 'source' => $c->source, 'notes' => $c->notes,
            'project' => $c->project ? ['id' => $c->project->id, 'docket' => $c->project->docket_number, 'name' => $c->project->project_name] : null,
            'client' => $c->client ? ['id' => $c->client->id, 'code' => $c->client->client_code, 'name' => $c->client->legal_name ?: $c->client->company_name] : null,
            'billed_invoice' => $c->billedInvoice ? ['id' => $c->billedInvoice->id, 'uin' => $c->billedInvoice->invoice_uin] : null,
            'document' => $c->document ? ['file_name' => $c->document->file_name, 'storage_path' => $c->document->storage_path] : null,
            'created_by' => $c->createdBy?->name,
        ];
    }

    private function audit(Request $request, string $action, MatterCost $cost): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'subject_type' => 'MatterCost', 'subject_id' => $cost->id,
            'metadata' => ['kind' => $cost->kind, 'amount_bcy' => (float) $cost->amount_bcy, 'project_id' => $cost->project_id],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
    }
}
