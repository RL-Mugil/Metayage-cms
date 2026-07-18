<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "who is a case-related person" — the rule that
 * governs both the private broadcast channel authorization and every
 * ProjectChatController action.
 *
 * A user may read/post in a case chat when ANY of the following hold:
 *   1. Firm-wide oversight roles: super_admin, partner (director-level),
 *      finance (accountant), hr. These see every case chat.
 *   2. They are explicitly assigned to THIS case: assigned partner, case
 *      manager, secondary manager, patent representative (patent_engineer),
 *      or a member of assigned_team[].
 *   3. Galvanizer whose circle covers the case.
 *   4. The client-portal user(s) of the case's client.
 *
 * Plain "manager"/"associate"/"paralegal" staff get in only via rule 2 — being
 * assigned keeps unrelated staff out of a confidential matter.
 */
class CaseChatAccess
{
    /** Roles that can see every case chat regardless of assignment. */
    private const FIRM_WIDE_ROLES = ['super_admin', 'partner', 'finance', 'hr'];

    public static function canAccess(User $user, Project $project): bool
    {
        // 1. Firm-wide oversight.
        if (in_array($user->role, self::FIRM_WIDE_ROLES, true)) {
            return true;
        }

        // 4. Client-portal users of this matter's client.
        if ($user->isClientRole()) {
            return self::isClientUserOfProject($user, $project);
        }

        // 3. Galvanizer scoped to the case circle.
        if ($user->isGalvanizer()) {
            return $user->canAccessCircle($project->circle);
        }

        // 2. Explicitly assigned to this case.
        return self::isAssigned($user, $project);
    }

    /** Is the user attached to this specific case in any staffing slot? */
    public static function isAssigned(User $user, Project $project): bool
    {
        if (in_array($user->id, [
            $project->assigned_partner_id,
            $project->assigned_manager_id,
            $project->secondary_manager_id,
            $project->patent_engineer_id,
        ], true)) {
            return true;
        }

        $team = $project->assigned_team ?? [];
        if (is_array($team) && in_array((string) $user->id, array_map('strval', $team), true)) {
            return true;
        }

        return false;
    }

    private static function isClientUserOfProject(User $user, Project $project): bool
    {
        if (! $project->client_id) {
            return false;
        }
        $client = $project->relationLoaded('client') ? $project->client : Client::find($project->client_id);

        return $client !== null && (int) $client->portal_user_id === (int) $user->id;
    }

    /**
     * The set of users who are participants of this case chat, for the
     * participant roster, @mention autocomplete and read-receipt avatars.
     * Firm-wide roles are intentionally NOT expanded here (could be hundreds);
     * they still have access, they just aren't listed as named participants.
     *
     * @return Collection<int, User>
     */
    public static function participants(Project $project): Collection
    {
        $ids = collect([
            $project->assigned_partner_id,
            $project->assigned_manager_id,
            $project->secondary_manager_id,
            $project->patent_engineer_id,
        ]);

        $team = $project->assigned_team ?? [];
        if (is_array($team)) {
            $ids = $ids->merge($team);
        }

        $ids = $ids->filter()->map(fn ($id) => (int) $id)->unique();

        $users = User::query()
            ->whereIn('id', $ids->all())
            ->get(['id', 'name', 'email', 'role', 'avatar_url']);

        // Include the client-portal user, if any.
        $client = $project->relationLoaded('client') ? $project->client : Client::find($project->client_id);
        if ($client && $client->portal_user_id) {
            $portalUser = User::find($client->portal_user_id, ['id', 'name', 'email', 'role', 'avatar_url']);
            if ($portalUser) {
                $users->push($portalUser);
            }
        }

        return $users->unique('id')->values();
    }
}
