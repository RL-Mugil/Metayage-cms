<?php

namespace App\Http\Controllers;

use App\Mail\PortalInviteMail;
use App\Models\Client;
use App\Models\User;
use App\Services\ClientPortalUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PortalController extends Controller
{
    /** Roles that may CRUD portal accounts (add/remove/reset-pw). */
    private const MANAGE_ROLES = ClientPortalUsers::MANAGE_ROLES;

    /** Any internal (non-client) staff may read portal data. */
    private function denyNonInternal(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if ($request->user()->isClientRole() || $request->user()->role === 'inventor') {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    /** Write/CRUD operations require MANAGE_ROLES. */
    private function denyUnauthorized(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! in_array($request->user()->role, self::MANAGE_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    /**
     * Resolve every portal account tied to a client set, including the
     * primary admin on clients.portal_user_id and any secondary contact users.
     */
    private function portalUserIdsForClients(array $clientIds): array
    {
        $contactEmails = DB::table('client_contacts')
            ->whereIn('client_id', $clientIds)
            ->pluck('email')
            ->filter();

        $ids = User::query()
            ->whereIn('role', User::CLIENT_ROLES)
            ->when($contactEmails->isNotEmpty(), fn ($query) => $query->whereIn('email', $contactEmails))
            ->pluck('id');

        $primaryIds = Client::whereIn('id', $clientIds)
            ->whereNotNull('portal_user_id')
            ->pluck('portal_user_id');

        return $ids
            ->merge($primaryIds)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Immediately log out all portal users of the given clients:
     * revoke Sanctum tokens and kill their DB-backed sessions.
     */
    private function revokePortalAccess(array $clientIds): void
    {
        $userIds = $this->portalUserIdsForClients($clientIds);
        if ($userIds === []) return;

        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->whereIn('tokenable_id', $userIds)
            ->delete();
        DB::table('sessions')->whereIn('user_id', $userIds)->delete();
    }

    public function clients(Request $request)
    {
        if ($deny = $this->denyNonInternal($request)) return $deny;

        return response()->json(
            Client::whereNotNull('portal_user_id')->orderBy('company_name')->limit(500)->get()->map(fn ($c) => [
                'id'               => $c->id,
                'client_code'      => $c->client_code,
                'company_name'     => $c->company_name ?? $c->legal_name,
                'portal_enabled'   => (bool) $c->portal_enabled,
                'portal_invited_at'=> $c->portal_invited_at?->toDateTimeString(),
                'portal_user_id'   => $c->portal_user_id,
            ])
        );
    }

    public function toggle(Request $request, $id)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $client = Client::findOrFail($id);
        $client->portal_enabled = ! $client->portal_enabled;
        if ($client->portal_enabled && ! $client->portal_invited_at) {
            $client->portal_invited_at = now();
        }
        $client->save();

        // Disabling a portal logs its users out immediately.
        if (! $client->portal_enabled) {
            $this->revokePortalAccess([$client->id]);
        }

        return response()->json(['ok' => true, 'portal_enabled' => $client->portal_enabled]);
    }

    /** Mark all portal-disabled clients invited and notify the actor. */
    public function inviteAll(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $count = Client::where('portal_enabled', false)->update([
            'portal_invited_at' => now(),
            'portal_enabled'    => true,
        ]);

        DB::table('ip_notifications')->insert([
            'user_id'     => $request->user()->id,
            'type'        => 'portal_invite',
            'title'       => 'Portal invitations sent',
            'description' => "Invitations recorded for {$count} inactive clients",
            'meta'        => json_encode(['count' => $count]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json(['ok' => true, 'invited' => $count]);
    }

    /**
     * Create a client portal with its first (primary) login, as the client's Manager (Admin).
     * The firm admin sets the password; the invite email follows unless emails are paused.
     */
    public function create(Request $request, ClientPortalUsers $portalUsers)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $validated = $request->validate([
            'client_id'   => 'required|integer|exists:clients,id',
            'name'        => 'nullable|string|max:255',
            'email'       => 'required|email|max:255',
            'phone'       => 'nullable|string|max:30',
            'designation' => ['nullable', Rule::in(array_diff(ClientPortalUsers::DESIGNATIONS, ['Inventor']))],
            'password'    => ['required', 'max:100', PasswordRule::min(8)->mixedCase()->symbols()],
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $companyName = $client->company_name ?? $client->legal_name ?? 'Client';
        $row = $portalUsers->add($client, [
            'name'        => $validated['name'] ?? $companyName,
            'email'       => $validated['email'],
            'phone'       => $validated['phone'] ?? null,
            'designation' => $validated['designation'] ?? 'Manager',
            'password'    => $validated['password'],
        ], $request->user(), $request->ip(), $request->userAgent());

        DB::table('ip_notifications')->insert([
            'user_id'     => $request->user()->id,
            'type'        => 'portal_invite',
            'title'       => 'Client portal created',
            'description' => "Created by {$request->user()->name} for {$companyName} ({$row['email']})",
            'meta'        => json_encode(['client_id' => $client->id, 'email' => $row['email']]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json([
            'ok'             => true,
            'email'          => $row['email'],
            'portal_user_id' => $client->fresh()->portal_user_id,
        ], 201);
    }

    /** Every portal login of a client: contact logins (with designation) and its inventors' logins. */
    public function clientUsers(Request $request, $id, ClientPortalUsers $portalUsers)
    {
        if ($deny = $this->denyNonInternal($request)) return $deny;

        return response()->json($portalUsers->list(Client::findOrFail($id)));
    }

    /** Add a portal login to a client; access follows the designation (see ClientPortalUsers). */
    public function addClientUser(Request $request, $id, ClientPortalUsers $portalUsers)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|max:255|unique:users,email',
            'phone'       => 'nullable|string|max:30',
            'designation' => ['required', Rule::in(ClientPortalUsers::DESIGNATIONS)],
            'password'    => 'required|string|min:8|max:100',
            // An Inventor's Form 1 particulars; checked in ClientPortalUsers::inventorParticulars().
            'inventor'    => 'nullable|array',
        ]);

        $row = $portalUsers->add(Client::findOrFail($id), $validated, $request->user(), $request->ip(), $request->userAgent());

        return response()->json(['ok' => true, 'user' => $row], 201);
    }

    /** Invite-email switch shown at the top of the Client Portal page (paused by default). */
    public function settings(Request $request)
    {
        if ($deny = $this->denyNonInternal($request)) return $deny;

        return response()->json(['emails_paused' => ClientPortalUsers::emailsPaused()]);
    }

    public function updateSettings(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $validated = $request->validate(['emails_paused' => 'required|boolean']);
        DB::transaction(function () use ($request, $validated) {
            ClientPortalUsers::setEmailsPaused($validated['emails_paused']);
            \App\Models\AuditLog::create([
                'user_id'      => $request->user()->id,
                'action'       => 'portal_emails_'.($validated['emails_paused'] ? 'paused' : 'resumed'),
                'subject_type' => 'SystemSetting',
                'subject_id'   => 0,
                'ip_address'   => $request->ip(),
                'user_agent'   => $request->userAgent(),
            ]);
        });

        return response()->json(['emails_paused' => ClientPortalUsers::emailsPaused()]);
    }

    /** Remove a non-primary portal login (an inventor keeps their inventor record, without a login). */
    public function removeClientUser(Request $request, $clientId, $userId, ClientPortalUsers $portalUsers)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $portalUsers->remove(Client::findOrFail($clientId), User::findOrFail($userId), $request->user(), $request->ip());

        return response()->json(['ok' => true]);
    }

    /** Reset password for any individual portal user of a client. */
    public function resetUserPassword(Request $request, $clientId, $userId, ClientPortalUsers $portalUsers)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $request->validate(['password' => 'required|string|min:8|max:100']);

        $client = Client::findOrFail($clientId);
        $user   = User::findOrFail($userId);

        // Contact logins, the primary, and the client's inventors' logins.
        if (! $portalUsers->belongsTo($client, $user)) {
            return response()->json(['message' => 'This user is not linked to this client.'], 422);
        }

        $user->password = Hash::make($request->input('password'));
        $user->save();
        $user->tokens()->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Bulk action on portal clients.
     * action: 'enable' | 'disable' | 'delete'
     * ids: client IDs
     */
    public function bulk(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $validated = $request->validate([
            'action' => 'required|in:enable,disable,delete',
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'integer|exists:clients,id',
        ]);

        $ids    = $validated['ids'];
        $action = $validated['action'];

        if ($action === 'enable') {
            Client::whereIn('id', $ids)->update([
                'portal_enabled'    => true,
                'portal_invited_at' => now(),
            ]);
        } elseif ($action === 'disable') {
            Client::whereIn('id', $ids)->update(['portal_enabled' => false]);
            $this->revokePortalAccess($ids);
        } elseif ($action === 'delete') {
            $userIds = $this->portalUserIdsForClients($ids);
            if ($userIds !== []) {
                $this->revokePortalAccess($ids);
                User::whereIn('id', $userIds)->delete();
            }

            // Strip portal data from client records
            Client::whereIn('id', $ids)->update([
                'portal_enabled'    => false,
                'portal_invited_at' => null,
                'portal_user_id'    => null,
            ]);
        }

        return response()->json(['ok' => true, 'affected' => count($ids)]);
    }

    /** Reset password for a portal user directly by client ID. */
    public function resetPassword(Request $request, $clientId)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $request->validate(['password' => 'required|string|min:6']);

        $client = Client::findOrFail($clientId);

        if (! $client->portal_user_id) {
            return response()->json(['message' => 'No portal user linked to this client. Create the portal first.'], 422);
        }

        $user = User::findOrFail($client->portal_user_id);
        $user->password = Hash::make($request->input('password'));
        $user->save();
        $user->tokens()->delete();

        return response()->json(['ok' => true]);
    }

    public function recentActivity(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $logs = DB::table('audit_logs')
            ->join('users', 'audit_logs.user_id', '=', 'users.id')
            ->whereIn('audit_logs.action', [
                'portal_invite_sent', 'portal_enabled', 'portal_disabled',
                'portal_password_reset', 'create', 'update',
            ])
            ->where(function ($q) {
                $q->where('audit_logs.subject_type', 'Client')
                  ->orWhereIn('audit_logs.action', ['portal_invite_sent', 'portal_enabled', 'portal_disabled', 'portal_password_reset']);
            })
            ->orderByDesc('audit_logs.created_at')
            ->limit(15)
            ->select('audit_logs.id', 'audit_logs.action', 'audit_logs.subject_type', 'audit_logs.subject_id', 'audit_logs.metadata', 'audit_logs.created_at', 'users.name as user_name')
            ->get()
            ->map(fn ($r) => [
                'id'           => $r->id,
                'action'       => $r->action,
                'subject_type' => $r->subject_type,
                'subject_id'   => $r->subject_id,
                'user_name'    => $r->user_name,
                'metadata'     => is_string($r->metadata) ? json_decode($r->metadata, true) : $r->metadata,
                'created_at'   => $r->created_at,
            ]);

        return response()->json($logs);
    }

    /**
     * Return distinct internal users assigned to the authenticated client's projects.
     * Groups by staff member, listing which projects they are on and in what role (PCM/SCM/PR/Attorney).
     */
    public function myTeam(Request $request)
    {
        $user = $request->user();
        if (! $user->isClientRole()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
        if (! $client) {
            return response()->json(['message' => 'No client record linked to your account.'], 403);
        }

        $projects = \App\Models\Project::with([
            'partner:id,name,email,avatar_url',
            'manager:id,name,email,avatar_url',
            'secondaryManager:id,name,email,avatar_url',
            'patentEngineer:id,name,email,avatar_url',
        ])
        ->where('client_id', $client->id)
        ->whereNotIn('status', ['Archived', 'Completed'])
        ->get(['id', 'project_code', 'docket_number', 'project_name', 'status',
               'assigned_partner_id', 'assigned_manager_id', 'secondary_manager_id', 'patent_engineer_id']);

        // Accumulate per-user role assignments
        $teamMap = [];

        foreach ($projects as $p) {
            $entries = [
                ['user' => $p->partner,          'role' => 'Attorney'],
                ['user' => $p->manager,           'role' => 'PCM'],
                ['user' => $p->secondaryManager,  'role' => 'SCM'],
                ['user' => $p->patentEngineer,    'role' => 'PR'],
            ];

            foreach ($entries as ['user' => $staffUser, 'role' => $role]) {
                if (! $staffUser) continue;
                $uid = $staffUser->id;
                if (! isset($teamMap[$uid])) {
                    $teamMap[$uid] = [
                        'id'         => $uid,
                        'name'       => $staffUser->name,
                        'email'      => $staffUser->email,
                        'avatar_url' => $staffUser->avatar_url,
                        'roles'      => [],
                        'projects'   => [],
                    ];
                }
                if (! in_array($role, $teamMap[$uid]['roles'])) {
                    $teamMap[$uid]['roles'][] = $role;
                }
                $teamMap[$uid]['projects'][] = [
                    'id'           => $p->id,
                    'project_code' => $p->project_code,
                    'docket_number'=> $p->docket_number,
                    'project_name' => $p->project_name,
                    'status'       => $p->status,
                    'your_role'    => $role,
                ];
            }
        }

        $roleOrder = ['Attorney' => 0, 'PCM' => 1, 'SCM' => 2, 'PR' => 3];
        $sorted = collect(array_values($teamMap))->sortBy(fn ($m) => $roleOrder[$m['roles'][0]] ?? 99)->values();

        return response()->json($sorted);
    }
}
