<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ZohoQuoteExport;
use App\Http\PaginationHelper;
use App\Services\ZohoApiBudget;
use App\Services\ZohoBooksService;
use App\Services\ZohoMirrorSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['super_admin', 'partner'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $query = AuditLog::with('user:id,name,email,avatar_url')
            ->orderBy('created_at', 'desc');

        if ($request->filled('action')) {
            $query->where('action', 'ilike', '%' . $request->action . '%');
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('action', 'ilike', $term)
                  ->orWhere('subject_type', 'ilike', $term)
                  ->orWhereRaw("metadata::text ILIKE ?", [$term]);
            });
        }

        return response()->json(PaginationHelper::paginate($query, $request, 50));
    }

    public function zohoMonitoring(Request $request, ZohoBooksService $zoho, ZohoMirrorSync $sync, ZohoApiBudget $budget)
    {
        if (! in_array($request->user()->role, ['super_admin', 'partner'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $scope = $zoho->scope();
        $cycle = DB::table('zoho_sync_progress')
            ->where(['scope' => $scope, 'resource' => '__cycle'])
            ->first();
        $progress = DB::table('zoho_sync_progress')
            ->where('scope', $scope)->where('resource', '!=', '__cycle')
            ->orderBy('resource')->get(['resource', 'next_page', 'started_at', 'completed_at']);
        $runs = DB::table('integration_logs')
            ->where(['slug' => 'zoho', 'event_type' => 'sync'])
            ->orderByDesc('id')->limit(25)
            ->get(['id', 'status', 'summary', 'created_at']);

        return response()->json([
            'scope' => $scope,
            'budget' => $budget->status($scope),
            'cycle' => $cycle,
            'pending_snapshots' => $sync->pending()->count(),
            'progress' => $progress,
            'recent_runs' => $runs,
        ]);
    }

    public function zohoControlPanel(Request $request, ZohoBooksService $zoho, ZohoApiBudget $budget)
    {
        if (! in_array($request->user()->role, ['super_admin', 'partner'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $scope = $zoho->scope();
        $portalEvents = AuditLog::query()
            ->whereIn('action', [
                'zoho_quote_send_started', 'zoho_quote_created', 'zoho_quote_reconciled',
                'zoho_quote_rejected', 'zoho_quote_uncertain', 'zoho_quote_deleted_manually',
                'zoho_sync_conflict', 'zoho_invoice_conversion_started', 'zoho_invoice_converted',
            ])
            ->orderByDesc('id')->limit(30)->get(['id', 'action', 'subject_type', 'subject_id', 'metadata', 'created_at']);

        $pollEvents = DB::table('integration_logs')
            ->where('slug', 'zoho')
            ->whereIn('event_type', ['sync', 'poll', 'webhook'])
            ->orderByDesc('id')->limit(30)
            ->get(['id', 'event_type', 'status', 'summary', 'payload', 'created_at']);

        $exports = ZohoQuoteExport::where('scope', $scope)->orderByDesc('updated_at')->limit(50)
            ->get(['id', 'source_type', 'source_id', 'client_id', 'project_id', 'reference', 'estimate_id', 'state', 'updated_at']);
        $webhooks = DB::table('zoho_webhook_events')->where('scope', $scope)->whereNull('processed_at');
        $staleExports = ZohoQuoteExport::where('scope', $scope)
            ->whereIn('state', ['sending', 'uncertain'])
            ->where('updated_at', '<', now()->subMinutes(5))->count();
        $lastPoll = DB::table('integration_logs')->where('slug', 'zoho')->where('event_type', 'sync')->where('status', 'ok')->orderByDesc('id')->value('created_at');
        $duplicateChecks = AuditLog::whereIn('action', ['zoho_quote_send_started', 'zoho_quote_created', 'zoho_quote_uncertain'])->count();
        $conflicts = AuditLog::where('action', 'zoho_sync_conflict')->orderByDesc('id')->limit(30)->get(['id', 'subject_type', 'subject_id', 'metadata', 'created_at']);

        return response()->json([
            'scope' => $scope,
            'plan_mode' => 'standard_polling',
            'polling_interval_minutes' => 5,
            'budget' => $budget->status($scope),
            'last_successful_poll' => $lastPoll,
            'waiting' => [
                'quote_exports' => (clone $exports)->whereIn('state', ['sending', 'uncertain'])->count(),
                'webhook_events' => $webhooks->count(),
                'stale_records' => $staleExports,
            ],
            'duplicate_prevention_checks' => $duplicateChecks,
            'conflicts' => $conflicts,
            'portal_events' => $portalEvents,
            'poll_events' => $pollEvents,
            'quote_exports' => $exports,
            'stale_sla_records' => $staleExports,
        ]);
    }
}
