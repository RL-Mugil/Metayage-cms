<?php

namespace App\Services;

use App\Models\PatentInvoiceIn;
use App\Models\Client;
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

    /** Unique nine-character UIN prefixes (client + invention + office). */
    public function globalDocketPrefixIndex(): Collection
    {
        return Project::query()
            ->whereNotNull('docket_number')->where('docket_number', '!=', '')
            ->get(['id', 'client_id', 'docket_number'])
            ->groupBy(fn ($p) => substr(strtoupper(trim((string) $p->docket_number)), 0, 9))
            ->filter(fn ($group, $prefix) => strlen((string) $prefix) === 9 && $group->count() === 1)
            ->map(fn ($group) => $group->first());
    }

    /** Zoho customer id -> local client and canonical client code. */
    public function zohoClientIndex(): Collection
    {
        return Client::query()->whereNotNull('zoho_contact_id')->whereNotNull('client_code')
            ->get(['id', 'zoho_contact_id', 'client_code'])
            ->keyBy(fn ($client) => (string) $client->zoho_contact_id);
    }

    /** Projects grouped by client for legacy invoice-number matching. */
    public function clientProjectIndex(): Collection
    {
        return Project::query()->whereNotNull('docket_number')->where('docket_number', '!=', '')
            ->get(['id', 'client_id', 'docket_number'])
            ->groupBy('client_id');
    }

    /** @return array{project_id: ?int, client_id: ?int, docket_number: ?string, source: ?string} */
    public function match(string $number, string $type, Collection $uinIndex, Collection $docketIndex, ?Collection $prefixIndex = null, ?Collection $zohoClients = null, ?Collection $clientProjects = null, ?string $zohoContactId = null): array
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

        // Zoho appends its own transaction suffix to the case UIN. A case UIN
        // is therefore a prefix of the invoice/quote number (for example
        // 269M188INPRV302J). Resolve only the longest unique docket prefix.
        $prefixMatches = $docketIndex->keys()->filter(fn ($caseUin) => str_starts_with($docket, (string) $caseUin));
        if ($prefixMatches->isNotEmpty()) {
            $longest = $prefixMatches->max(fn ($caseUin) => strlen((string) $caseUin));
            $matches = $prefixMatches->filter(fn ($caseUin) => strlen((string) $caseUin) === $longest);
            if ($matches->count() === 1) {
                $project = $docketIndex->get($matches->first());
                return ['project_id' => $project->id, 'client_id' => $project->client_id,
                    'docket_number' => $project->docket_number, 'source' => 'uin_prefix'];
            }
        }

        // Zoho UINs use the first nine characters for the case identity; the
        // final three characters are the service code. Only unique prefixes are
        // eligible so different service records cannot be linked accidentally.
        $prefix = substr($docket, 0, 9);
        if ($prefixIndex && strlen($prefix) === 9 && ($project = $prefixIndex->get($prefix))) {
            return [
                'project_id' => $project->id,
                'client_id' => $project->client_id,
                'docket_number' => $project->docket_number,
                'source' => 'uin_prefix',
            ];
        }

        // Older Zoho numbers omit the client's trailing M and append an invoice
        // sequence/service variant. Match those numbers within the resolved
        // Zoho customer using client + invention number + jurisdiction. Only a
        // unique candidate is eligible; ambiguity must remain visible to staff.
        if ($zohoClients && $clientProjects) {
            $client = $zohoClients->get((string) $zohoContactId);
            if ($client) {
                $clientCode = strtoupper(trim((string) $client->client_code));
                $compactClientCode = preg_replace('/M$/', '', $clientCode);
                $base = preg_replace('#/\d+$#', '', $number);
                $remainder = str_starts_with($base, $clientCode)
                    ? substr($base, strlen($clientCode))
                    : (str_starts_with($base, $compactClientCode) ? substr($base, strlen($compactClientCode)) : '');
                $invention = substr($remainder, 0, 3);
                $office = substr($remainder, 3, 2);
                if (preg_match('/^\d{3}[A-Z]{2}/', $remainder) && $invention !== '' && $office !== '') {
                    $candidates = $clientProjects->get($client->id, collect())->filter(function ($project) use ($clientCode, $invention, $office) {
                        $docket = strtoupper(trim((string) $project->docket_number));
                        $docketRemainder = str_starts_with($docket, $clientCode) ? substr($docket, strlen($clientCode)) : '';
                        return substr($docketRemainder, 0, 3) === $invention && substr($docketRemainder, 3, 2) === $office;
                    });
                    if ($candidates->count() === 1) {
                        $project = $candidates->first();
                        return ['project_id' => $project->id, 'client_id' => $project->client_id,
                            'docket_number' => $project->docket_number, 'source' => 'client_uin'];
                    }
                }
            }
        }

        return ['project_id' => null, 'client_id' => null, 'docket_number' => null, 'source' => null];
    }

}
