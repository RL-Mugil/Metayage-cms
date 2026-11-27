<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who is in a client's discussions: the client's own portal users and the firm's people on the
 * client: PCM, SCM, PR and attorney of the client's cases, the Client Manager, Accounts (finance
 * roles and the Financial access list), paralegals and directors. A client's users may direct
 * message exactly these staff.
 */
class ClientTeam
{
    /** @return Collection<int, User> firm staff on this client (active) */
    public static function staff(Client $client): Collection
    {
        $caseIds = Project::where('client_id', $client->id)
            ->get(['assigned_partner_id', 'assigned_manager_id', 'secondary_manager_id', 'patent_engineer_id'])
            ->flatMap(fn ($p) => [$p->assigned_partner_id, $p->assigned_manager_id, $p->secondary_manager_id, $p->patent_engineer_id]);
        $ids = $caseIds->push($client->account_manager_id)
            ->merge(DataAccess::financeAccessList())
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        return User::query()
            ->whereIn('role', User::FIRM_STAFF_ROLES)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->where(fn ($q) => $q->whereIn('id', $ids ?: [0])->orWhereIn('role', ['finance', 'paralegal', 'partner', 'director']))
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'avatar_url']);
    }

    /** @return Collection<int, User> the client's own portal users */
    public static function clientUsers(Client $client): Collection
    {
        return User::whereIn('id', $client->portalUserIds() ?: [0])->get(['id', 'name', 'role', 'avatar_url']);
    }

    /** @return array<int, int> everyone in the client's discussions */
    public static function memberIds(Client $client): array
    {
        return self::staff($client)->pluck('id')->merge(self::clientUsers($client)->pluck('id'))->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
