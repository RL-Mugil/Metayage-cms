<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\ZohoInvoice;
use App\Services\ZohoBooksService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only. Never writes to Zoho Books — see ZohoBooksService for the enforced
 * GET-only surface. Everything here is served from the local `zoho_invoices` mirror
 * (kept fresh by `zoho:sync`, see App\Console\Commands\SyncZohoBooks) — no live Zoho
 * calls happen on a page load at all; only the sync job and the Integrations "Test
 * Connection"/"Sync Now" actions ever talk to Zoho directly.
 */
class ZohoBooksController extends Controller
{
    private const READ_ROLES  = ['super_admin', 'partner', 'manager', 'hr', 'associate', 'paralegal', 'finance', 'galvanizer'];
    private const WRITE_ROLES = ['super_admin', 'manager', 'galvanizer'];

    public function __construct(private ZohoBooksService $zoho)
    {
    }

    public function clientSummary(Request $request, int $clientId)
    {
        $user   = $request->user();
        $client = Client::findOrFail($clientId);

        if ($deny = $this->gateClient($user, $client)) {
            return $deny;
        }

        return $this->respondWithSummary($client->id);
    }

    /** Convenience for a client-role user viewing their own billing (Financial.tsx). */
    public function mySummary(Request $request)
    {
        $user = $request->user();
        if (! $user->isClientRole()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $client = Client::forUser($user);
        if (! $client) {
            return response()->json(['message' => 'No client account linked to your login.'], 404);
        }

        return $this->respondWithSummary($client->id);
    }

    public function projectSummary(Request $request, int $projectId)
    {
        $user    = $request->user();
        $project = Project::with('client')->findOrFail($projectId);
        $this->authorize('view', $project);

        // Mirrors MatterWorkspaceService::build()'s can_view_financials gate exactly —
        // the project page's Zoho panel lives inside that same "costs" tab.
        $canViewFinancials = ! $user->isClientRole() && ! in_array($user->role, ['hr', 'associate'], true);
        if (! $canViewFinancials) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $this->respondWithSummary($project->client_id, $project->id, "zoho_project_summary_{$project->id}");
    }

    /** Every synced Zoho invoice/quote across every case — the Financial Suite "Zoho Books" tab. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, self::READ_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = ZohoInvoice::with(['client:id,company_name,client_code', 'project:id,docket_number,project_name'])
            ->orderByDesc('txn_date');

        if ($request->filled('type')) {
            $query->where('zoho_type', $request->get('type') === 'quote' ? 'quote' : 'invoice');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        }
        if ($request->filled('search')) {
            $s = $request->get('search');
            $query->where(function ($q) use ($s) {
                $q->where('number', 'ilike', "%{$s}%")
                  ->orWhereHas('client', fn ($c) => $c->where('company_name', 'ilike', "%{$s}%"))
                  ->orWhereHas('project', fn ($p) => $p->where('docket_number', 'ilike', "%{$s}%"));
            });
        }

        if ($user->isGalvanizer()) {
            $query->whereHas('project', fn ($q) => $user->applyProjectScope($q));
        } elseif ($user->role === 'manager') {
            $query->whereHas('project', fn ($q) => $q->where('assigned_manager_id', $user->id));
        }

        $perPage = min((int) $request->get('per_page', 100), 500);
        $result  = $query->paginate($perPage);

        return response()->json([
            'data' => $result->getCollection()->map(fn (ZohoInvoice $r) => [
                'id'             => $r->id,
                'type'           => $r->zoho_type,
                'number'         => $r->number,
                'client'         => $r->client?->company_name,
                'client_code'    => $r->client?->client_code,
                'docket_number'  => $r->project?->docket_number,
                'project_name'   => $r->project?->project_name,
                'date'           => $r->txn_date?->toDateString(),
                'due_date'       => $r->due_date?->toDateString(),
                'status'         => $r->status,
                'total'          => (float) $r->total,
                'balance'        => $r->balance !== null ? (float) $r->balance : null,
                'currency'       => $r->currency,
                'url'            => $r->url,
                'match_source'   => $r->match_source,
                'synced_at'      => $r->synced_at?->toIso8601String(),
            ]),
            'total'        => $result->total(),
            'per_page'     => $result->perPage(),
            'current_page' => $result->currentPage(),
            'last_page'    => $result->lastPage(),
        ]);
    }

    /** Batch UIN lookup against the local mirror only — no live Zoho calls. Used by the India Records table. */
    public function matchBatch(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, self::READ_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate(['uins' => 'required|array|max:500', 'uins.*' => 'string']);
        $uins      = array_map(fn ($u) => strtoupper(trim($u)), $validated['uins']);

        $rows = ZohoInvoice::whereIn(DB::raw('UPPER(number)'), $uins)->get(['number', 'status', 'balance', 'total', 'url']);

        $map = [];
        foreach ($rows as $row) {
            $map[strtoupper((string) $row->number)] = [
                'status' => $row->status, 'balance' => $row->balance, 'total' => $row->total, 'url' => $row->url,
            ];
        }

        return response()->json($map);
    }

    /** Last 12 months of paid Zoho invoice totals, for the Analytics revenue chart. */
    public function monthlyAnalytics(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isClientRole() && ! in_array($user->role, self::READ_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = ZohoInvoice::where('zoho_type', 'invoice')->where('status', 'paid');
        if ($user->isClientRole()) {
            $query->where('client_id', optional(Client::forUser($user))->id ?? 0);
        } elseif ($user->isGalvanizer()) {
            $query->whereHas('project', fn ($q) => $user->applyProjectScope($q));
        }

        $since = now()->subMonths(11)->startOfMonth();
        $rows  = $query->where('txn_date', '>=', $since)->get(['txn_date', 'total']);

        $grouped = [];
        foreach ($rows as $row) {
            $key = $row->txn_date?->format('Y-m');
            if (! $key) {
                continue;
            }
            $grouped[$key] = ($grouped[$key] ?? 0) + (float) $row->total;
        }

        $result = [];
        foreach ($grouped as $month => $total) {
            $result[] = ['month' => $month, 'total' => $total];
        }

        return response()->json($result);
    }

    /** On-demand refresh for the "Sync Now" button in Integrations. */
    public function sync(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, self::WRITE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (! $this->zoho->isConfigured()) {
            return response()->json(['message' => 'Zoho Books is not connected.'], 422);
        }

        Artisan::call('zoho:sync');

        return response()->json(['ok' => true, 'message' => trim(Artisan::output()) ?: 'Sync complete.']);
    }

    private function respondWithSummary(int $clientId, ?int $projectId = null, ?string $cacheKey = null)
    {
        if (! $this->zoho->isConfigured()) {
            return response()->json(['message' => 'Zoho Books is not connected. Configure it under Integrations first.'], 422);
        }

        $cacheKey ??= "zoho_client_summary_{$clientId}";

        try {
            $summary = Cache::remember($cacheKey, 60, fn () => $this->buildSummary($clientId, $projectId));
        } catch (Throwable $e) {
            return response()->json(['message' => 'Failed to load Zoho Books data: ' . $e->getMessage()], 502);
        }

        return response()->json($summary, 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    private function gateClient($user, Client $client): ?JsonResponse
    {
        if ($user->isClientRole()) {
            return $client->isVisibleToUser($user) ? null : response()->json(['message' => 'Forbidden'], 403);
        }
        if (! in_array($user->role, self::READ_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($user->isGalvanizer() && ! $user->canAccessCircle($client->circle)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    private function buildSummary(int $clientId, ?int $projectId = null): array
    {
        $query = ZohoInvoice::where('client_id', $clientId)->with('project:id,docket_number');
        if ($projectId) {
            $query->where('project_id', $projectId);
        }
        $rows = $query->orderByDesc('txn_date')->get();

        $mapRow = fn (ZohoInvoice $r) => [
            'number'         => $r->number,
            'date'           => $r->txn_date?->toDateString(),
            'status'         => $r->status,
            'total'          => (float) $r->total,
            'balance'        => $r->balance !== null ? (float) $r->balance : null,
            'url'            => $r->url,
            'application_no' => $r->application_no,
            'patent_office'  => $r->patent_office,
            'case'           => $r->project_id ? [
                'project_id'    => $r->project_id,
                'docket_number' => $r->project?->docket_number,
                'source'        => $r->match_source,
            ] : null,
        ];

        $invoices  = $rows->where('zoho_type', 'invoice');
        $estimates = $rows->where('zoho_type', 'quote');

        $unpaidStatuses = ['overdue', 'unpaid', 'partially_paid', 'sent', 'viewed'];
        $outstanding    = $invoices->filter(fn ($r) => in_array($r->status, $unpaidStatuses, true))->sum('balance');

        return [
            'outstanding_balance' => (float) $outstanding,
            'invoices'            => $invoices->map($mapRow)->values()->all(),
            'estimates'           => $estimates->map($mapRow)->values()->all(),
        ];
    }
}
