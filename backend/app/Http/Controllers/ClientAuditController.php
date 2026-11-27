<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A client's own audit log (for their logins with "Audit log"): what their users did in the
 * portal (sign-ins, approvals, documents, payments, discussions) and changes to their logins and
 * designations, by them or by the firm. Never shows firm-internal activity.
 */
class ClientAuditController extends Controller
{
    private const LABELS = [
        'login' => 'Signed in', 'logout' => 'Signed out',
        'client_approve_quote' => 'Approved a quote', 'client_decline_quote' => 'Declined a quote',
        'resolve_approval' => 'Decided an approval', 'renewal_approved' => 'Approved a renewal',
        'client_approve_renewal_quote' => 'Approved a renewal quote', 'renewal_proof_submitted' => 'Uploaded a payment proof',
        'upload_document' => 'Uploaded a document', 'delete_document' => 'Deleted a document',
        'discussion_post' => 'Posted in a discussion', 'download_statement' => 'Downloaded the statement',
        'portal_user_added' => 'Added a portal user', 'portal_user_removed' => 'Removed a portal user',
        'portal_user_access_changed' => 'Changed a user\'s access',
        'client_designation_created' => 'Created a designation', 'client_designation_updated' => 'Changed a designation',
        'client_designation_deleted' => 'Deleted a designation', 'report_download' => 'Downloaded a report',
        'reset_internal_signature_pin' => 'Changed the signature PIN',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isClientRole(), 403);
        $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
        abort_unless($client, 404);
        $f = $request->validate(['user_id' => 'nullable|integer', 'search' => 'nullable|string|max:80', 'from' => 'nullable|date', 'to' => 'nullable|date',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:5|max:100']);

        $members = User::whereIn('id', $client->portalUserIds() ?: [0])->get(['id', 'name', 'email']);
        $memberIds = $members->pluck('id')->all();
        $query = AuditLog::query()->with('user:id,name')
            ->where(fn ($q) => $q->whereIn('user_id', $memberIds ?: [0])
                // Changes to their logins and designations made by the firm.
                ->orWhere(fn ($w) => $w->where('subject_type', 'Client')->where('subject_id', $client->id)
                    ->where(fn ($a) => $a->where('action', 'like', 'portal_user%')->orWhere('action', 'like', 'client_designation%'))))
            ->when(! empty($f['user_id']) && in_array((int) $f['user_id'], $memberIds, true), fn ($q) => $q->where('user_id', (int) $f['user_id']))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('action', 'ilike', '%'.str_replace(' ', '_', $s).'%'))
            ->orderByDesc('id');
        $page = $query->paginate((int) ($f['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $a) => [
                'id' => $a->id, 'at' => $a->created_at?->toIso8601String(),
                'user' => in_array($a->user_id, $memberIds, true) ? ($a->user?->name ?? '—') : 'The firm',
                'action' => self::LABELS[$a->action] ?? ucfirst(str_replace('_', ' ', (string) $a->action)),
                'what' => $this->what($a), 'ip' => in_array($a->user_id, $memberIds, true) ? $a->ip_address : null,
            ]),
            'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'users' => $members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
        ]);
    }

    /** A short, client-safe description of what the action was about. */
    private function what(AuditLog $a): ?string
    {
        $m = (array) ($a->metadata ?? []);
        foreach (['invoice_uin', 'docket_number', 'name', 'email', 'designation', 'file_name', 'type'] as $key) {
            if (! empty($m[$key]) && is_scalar($m[$key])) {
                return (string) $m[$key];
            }
        }

        return null;
    }
}
