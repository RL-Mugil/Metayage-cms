<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\Client;
use App\Models\User;
use App\Support\DataAccess;

class SearchClients extends Tool
{
    public function name(): string { return 'search_clients'; }

    public function title(): string { return 'Search clients'; }

    public function description(): string
    {
        return 'Find clients the user can see by name, client code or status. Returns code, name, type, country, city, status, Client Manager and the number of visible cases.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string', 'description' => 'Part of the client name or code, e.g. "Acme" or "382M".'],
            'status' => ['type' => 'string', 'enum' => ['Active', 'Inactive', 'Prospect', 'On Hold']],
            'limit' => $this->limitProp(),
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $query = Client::query()->with('accountManager:id,name');
        DataAccess::scopeManagedClients($query, $user);
        if ($q = $this->str($args, 'query')) {
            $like = $this->like($q);
            $query->where(fn ($w) => $w->where('client_code', 'ilike', $like)->orWhere('legal_name', 'ilike', $like)->orWhere('company_name', 'ilike', $like));
        }
        if ($s = $this->str($args, 'status')) {
            $query->where('status', $s);
        }
        $visibleCases = $this->cases($user)->select('id');
        $query->withCount(['projects as visible_cases' => fn ($p) => $p->whereIn('projects.id', $visibleCases)]);

        $total = (clone $query)->count();
        $rows = $query->orderBy('client_code')->limit($this->limit($args))->get()->map(fn (Client $c) => [
            'id' => $c->id, 'client_code' => $c->client_code, 'name' => $c->legal_name ?: $c->company_name,
            'client_type' => $c->client_type, 'country' => $c->country ?: $c->nationality, 'city' => $c->city,
            'status' => $c->status, 'client_manager' => $c->accountManager?->name, 'cases' => (int) $c->visible_cases,
        ])->all();

        return ['total' => $total, 'returned' => count($rows), 'truncated' => $total > count($rows), 'clients' => $rows];
    }
}
