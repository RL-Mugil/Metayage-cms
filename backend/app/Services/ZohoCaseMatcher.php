<?php

namespace App\Services;

use App\Models\PatentInvoiceIn;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Matches a Zoho invoice/estimate number to a portal case, globally across every
 * client — not scoped to a pre-resolved Zoho contact. Invoices are raised in Zoho
 * with the invoice_number set to the case's own UIN (PatentInvoiceController::computeUin):
 * the bare docket_number for the first invoice/quote on that docket, "docket/1" for the
 * second, "docket/2" for the third, etc. — per type (invoice vs quote counted separately).
 */
class ZohoCaseMatcher
{
    /** Every known UIN across all clients, keyed "UIN|type" -> {project_id, client_id, docket_number}. */
    public function globalUinIndex(): Collection
    {
        return PatentInvoiceIn::query()
            ->get(['type', 'invoice_uin', 'docket_number', 'project_id', 'client_id'])
            ->filter(fn ($r) => (string) $r->invoice_uin !== '')
            ->keyBy(fn ($r) => strtoupper(trim((string) $r->invoice_uin)) . '|' . $r->type);
    }

    /** Every project's bare docket_number -> {id, client_id}, for the "/N" fallback. */
    public function globalDocketIndex(): Collection
    {
        return Project::query()
            ->whereNotNull('docket_number')->where('docket_number', '!=', '')
            ->get(['id', 'client_id', 'docket_number'])
            ->keyBy(fn ($p) => strtoupper(trim((string) $p->docket_number)));
    }

    /** @return array{project_id: ?int, client_id: ?int, docket_number: ?string, source: ?string} */
    public function match(string $number, string $type, Collection $uinIndex, Collection $docketIndex): array
    {
        $number = strtoupper(trim($number));

        if ($uinHit = $uinIndex->get($number . '|' . $type)) {
            return [
                'project_id'    => $uinHit->project_id,
                'client_id'     => $uinHit->client_id,
                'docket_number' => $uinHit->docket_number,
                'source'        => 'uin',
            ];
        }

        // Strip the trailing "/N" invoice-sequence suffix and match the bare docket.
        $docket = preg_replace('#/\d+$#', '', $number);
        if ($project = $docketIndex->get($docket)) {
            return [
                'project_id'    => $project->id,
                'client_id'     => $project->client_id,
                'docket_number' => $docket,
                'source'        => 'docket',
            ];
        }

        return ['project_id' => null, 'client_id' => null, 'docket_number' => null, 'source' => null];
    }
}
