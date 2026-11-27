<?php

namespace App\Ai\ClientTools;

use App\Models\Approval;
use App\Models\PatentInvoiceIn;
use App\Models\User;

class MyApprovals extends ClientTool
{
    public function name(): string { return 'my_approvals'; }

    public function title(): string { return 'My approvals'; }

    public function description(): string
    {
        return 'Approvals waiting on the client (quotes to approve, decisions on their cases) and recent decided ones, with amount for quotes. Read-only: approve in the portal\'s Approvals page.';
    }

    protected function permission(): string { return 'approvals'; }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'pending_only' => ['type' => 'boolean', 'description' => 'Only approvals still waiting (default true).'],
            'limit' => $this->limitProp(),
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $query = Approval::with('project:id,docket_number')->where('client_id', $this->ownClient($user)?->id ?? 0)->where('type', 'client')->latest('id');
        if ($args['pending_only'] ?? true) {
            $query->where('status', 'Pending');
        }
        $rows = $query->limit($this->limit($args))->get()->map(fn (Approval $a) => [
            'title' => $a->title, 'status' => $a->status, 'sent' => self::date($a->created_at), 'docket_number' => $a->project?->docket_number,
            'amount' => $a->subject_type === 'PatentInvoiceIn' ? (float) PatentInvoiceIn::whereKey($a->subject_id)->value('invoice_amount') : null,
        ]);

        return ['returned' => $rows->count(), 'approvals' => $rows->all(), 'where_to_act' => '/approvals'];
    }
}
