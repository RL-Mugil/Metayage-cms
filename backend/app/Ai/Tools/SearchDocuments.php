<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Support\DataAccess;

class SearchDocuments extends Tool
{
    public function name(): string { return 'search_documents'; }

    public function title(): string { return 'Find documents'; }

    public function description(): string
    {
        return 'Find documents in the portal DMS by file name, category, case or client: returns name, type, category, size, version, uploader, date and the case, with a link to open it in the portal. File contents are never returned.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string', 'description' => 'Part of the file name.'],
            'category' => ['type' => 'string'],
            'case_id' => ['type' => 'integer'],
            'docket_number' => ['type' => 'string'],
            'client_id' => ['type' => 'integer'],
            'limit' => $this->limitProp(),
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $query = Document::with(['uploader:id,name', 'project:id,docket_number'])->orderByDesc('updated_at');
        if ($user->role === 'finance') {
            $query->whereNull('client_id')->whereNull('project_id');   // as on the Documents page: internal documents only
        } elseif (! DataAccess::seesAllCases($user)) {
            $clients = Client::query()->select('clients.id');
            DataAccess::scopeManagedClients($clients, $user);
            $query->where(fn ($q) => $q->whereIn('project_id', $this->cases($user)->select('projects.id'))
                ->orWhere(fn ($w) => $w->whereNull('project_id')->whereIn('client_id', $clients)));
        }
        if ($q = $this->str($args, 'query')) {
            $query->where('file_name', 'ilike', $this->like($q));
        }
        if ($c = $this->str($args, 'category')) {
            $query->where('category', 'ilike', $c);
        }
        if (! empty($args['case_id'])) {
            $query->where('project_id', (int) $args['case_id']);
        }
        if ($d = $this->str($args, 'docket_number')) {
            $query->whereHas('project', fn ($p) => $p->where('docket_number', $d));
        }
        if (! empty($args['client_id'])) {
            $cid = (int) $args['client_id'];
            $query->where(fn ($w) => $w->where('client_id', $cid)->orWhereHas('project', fn ($p) => $p->where('client_id', $cid)));
        }
        $total = (clone $query)->count();
        $base = rtrim((string) config('app.url'), '/');
        $rows = $query->limit($this->limit($args))->get()->map(fn (Document $d) => [
            'id' => $d->id, 'file_name' => $d->file_name, 'type' => $d->file_type, 'category' => $d->category,
            'size_kb' => $d->file_size ? (int) round($d->file_size / 1024) : null, 'version' => $d->current_version, 'status' => $d->status,
            'uploaded_by' => $d->uploader?->name, 'updated' => self::date($d->updated_at), 'docket_number' => $d->project?->docket_number,
            'open_in_portal' => $d->project_id ? "{$base}/projects/{$d->project_id}" : "{$base}/documents",
        ])->all();

        return ['total' => $total, 'returned' => count($rows), 'truncated' => $total > count($rows), 'documents' => $rows];
    }
}
