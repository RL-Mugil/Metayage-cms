<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\TrackerRow;
use App\Models\User;
use App\Support\DataAccess;

class SearchTracker extends Tool
{
    public function name(): string { return 'search_tracker'; }

    public function title(): string { return 'Project Tracker'; }

    public function description(): string
    {
        return 'Rows of the Project Tracker (daily work tracker by circle): docket, client, record type, PCM / SCM / PR, status, delivery due date, payment status and % complete. Filter by text, circle, person, status or overdue delivery.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string', 'description' => 'Docket, UIN or client name.'],
            'circle' => ['type' => 'string', 'description' => 'Circle name or letter.'],
            'mine' => ['type' => 'boolean', 'description' => 'Only rows where the user is PCM, SCM or PR.'],
            'status' => ['type' => 'string'],
            'overdue_delivery' => ['type' => 'boolean'],
            'limit' => $this->limitProp(),
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $uid = (int) $user->id;
        $query = TrackerRow::query()->with(['circle:id,name', 'pcmUser:id,name', 'scmUser:id,name', 'prUser:id,name']);
        if (! DataAccess::seesAllCases($user)) {
            $query->where(fn ($q) => $q->where('pcm_id', $uid)->orWhere('scm_id', $uid)->orWhere('pr_id', $uid)
                ->orWhereIn('project_id', $this->cases($user)->select('projects.id')));
        }
        if (! empty($args['mine'])) {
            $query->where(fn ($q) => $q->where('pcm_id', $uid)->orWhere('scm_id', $uid)->orWhere('pr_id', $uid));
        }
        if ($q = $this->str($args, 'query')) {
            $like = $this->like($q);
            $query->where(fn ($w) => $w->where('docket_number', 'ilike', $like)->orWhere('uin', 'ilike', $like)->orWhere('client_name', 'ilike', $like));
        }
        if ($c = $this->str($args, 'circle')) {
            $query->whereHas('circle', fn ($w) => $w->where('name', 'ilike', $this->like($c))->orWhere('slug', 'ilike', $c));
        }
        if ($s = $this->str($args, 'status')) {
            $query->where('status', 'ilike', $s);
        }
        if (! empty($args['overdue_delivery'])) {
            $query->whereNotNull('delivery_due_date')->where('delivery_due_date', '<', now()->toDateString())
                ->where(fn ($w) => $w->whereNull('percentage_of_completion')->orWhere('percentage_of_completion', '<', 100));
        }
        $total = (clone $query)->count();
        $rows = $query->orderByRaw('delivery_due_date IS NULL')->orderBy('delivery_due_date')->limit($this->limit($args))->get()->map(fn ($r) => [
            'docket_number' => $r->docket_number, 'uin' => $r->uin, 'client' => $r->client_name, 'record_type' => $r->record_type,
            'circle' => $r->circle?->name, 'pcm' => $r->pcmUser?->name, 'scm' => $r->scmUser?->name, 'pr' => $r->prUser?->name, 'status' => $r->status,
            'delivery_due' => self::date($r->delivery_due_date), 'payment_status' => $r->payment_status, 'percent_complete' => $r->percentage_of_completion,
        ])->all();

        return ['total' => $total, 'returned' => count($rows), 'truncated' => $total > count($rows), 'rows' => $rows];
    }
}
