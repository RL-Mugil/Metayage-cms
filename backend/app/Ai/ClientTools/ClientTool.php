<?php

namespace App\Ai\ClientTools;

use App\Ai\Tool;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Support\ClientAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * A read-only tool for a client's own login (AI Assistant, Claude connector, API keys): only
 * ever about the login's own client, only within its designation's access boxes, and never the
 * firm's internal data (fees split, staff workload, notes, other clients).
 */
abstract class ClientTool extends Tool
{
    /** The access box this tool needs (App\Support\ClientAccess). */
    abstract protected function permission(): string;

    public function availableTo(User $user): bool
    {
        return $user->isClientRole() && ClientAccess::can($user, $this->permission()) && $this->ownClient($user) !== null;
    }

    protected function ownClient(User $user): ?Client
    {
        return request()?->attributes->get('portal_client') ?? Client::forUser($user);
    }

    /** The client's own cases. */
    protected function ownCases(User $user): Builder
    {
        return Project::query()->where('client_id', $this->ownClient($user)?->id ?? 0);
    }
}
