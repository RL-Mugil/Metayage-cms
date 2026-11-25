<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class GetClient extends Tool
{
    public function name(): string { return 'get_client'; }

    public function title(): string { return 'Client details'; }

    public function description(): string
    {
        return 'One client in detail: profile, Client Manager, contacts (name, designation, email) and the cases the user can see. Pass client_id or client_code.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'client_id' => ['type' => 'integer'],
            'client_code' => ['type' => 'string', 'description' => 'e.g. "382M"'],
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $query = Client::query()->with(['accountManager:id,name', 'contacts']);
        if (! empty($args['client_id'])) {
            $client = $query->findOrFail((int) $args['client_id']);
        } elseif ($code = $this->str($args, 'client_code')) {
            $client = $query->where('client_code', $code)->firstOrFail();
        } else {
            throw new \InvalidArgumentException('Pass client_id or client_code.');
        }
        Gate::forUser($user)->authorize('view', $client);

        $cases = $this->cases($user)->where('client_id', $client->id)->orderByDesc('created_at')->limit(self::MAX_ROWS)
            ->get(['id', 'docket_number', 'project_name', 'service_code', 'patent_office_code', 'status', 'application_number', 'hard_deadline']);

        return [
            'id' => $client->id, 'client_code' => $client->client_code, 'name' => $client->legal_name ?: $client->company_name,
            'client_type' => $client->client_type, 'entity_type' => $client->entity_type, 'status' => $client->status,
            'country' => $client->country ?: $client->nationality, 'city' => $client->city, 'state' => $client->state,
            'gst_type' => $client->gst_type, 'client_manager' => $client->accountManager?->name, 'website' => $client->website,
            'contacts' => $client->contacts->map(fn ($c) => ['name' => $c->name, 'title' => $c->title, 'department' => $c->department, 'email' => $c->email])->values()->all(),
            'cases' => $cases->map(fn ($p) => [
                'id' => $p->id, 'docket_number' => $p->docket_number, 'title' => $p->project_name, 'service_code' => $p->service_code,
                'office' => $p->patent_office_code, 'status' => $p->status, 'application_number' => $p->application_number,
                'hard_deadline' => self::date($p->hard_deadline),
            ])->all(),
        ];
    }
}
