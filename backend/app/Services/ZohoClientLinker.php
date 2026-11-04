<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ZohoSnapshot;

/**
 * Keeps clients.zoho_contact_id filled. A link is only ever set when the client has none and no other client
 * already uses that Zoho customer. Local data only: no Zoho requests are made here.
 */
class ZohoClientLinker
{
    /** The user picked this Zoho customer for the client's quote: remember it. */
    public function linkChosen(?Client $client, string $customerId): bool
    {
        if (! $client || $customerId === '' || (string) $client->zoho_contact_id !== '') {
            return false;
        }
        if (Client::where('zoho_contact_id', $customerId)->where('id', '!=', $client->id)->exists()) {
            return false;
        }
        $client->update(['zoho_contact_id' => $customerId]);
        $this->audit($client, $customerId, 'chosen_for_quote');

        return true;
    }

    /** Link every unlinked client whose name equals exactly one active Zoho customer's name. Returns the number linked. */
    public function linkByName(string $scope): int
    {
        $byName = [];
        foreach (ZohoSnapshot::where('scope', $scope)->where('resource', 'contacts')->cursor() as $snapshot) {
            $contact = json_decode($snapshot->detail ?: $snapshot->summary, true) ?? [];
            if (($contact['status'] ?? '') !== 'active' || ($contact['contact_type'] ?? '') !== 'customer') {
                continue;
            }
            foreach (array_filter([$contact['contact_name'] ?? null, $contact['company_name'] ?? null]) as $name) {
                $byName[self::normalise((string) $name)][$snapshot->remote_id] = true;
            }
        }
        $used = Client::whereNotNull('zoho_contact_id')->pluck('zoho_contact_id')->flip()->all();
        $linked = 0;
        foreach (Client::where(fn ($q) => $q->whereNull('zoho_contact_id')->orWhere('zoho_contact_id', ''))->cursor() as $client) {
            $candidates = [];
            foreach (array_filter([$client->company_name, $client->legal_name]) as $name) {
                $candidates += $byName[self::normalise((string) $name)] ?? [];
            }
            if (count($candidates) !== 1) {
                continue;   // none, or ambiguous: left for a manual choice
            }
            $id = (string) array_key_first($candidates);
            if (isset($used[$id])) {
                continue;
            }
            $client->update(['zoho_contact_id' => $id]);
            $used[$id] = true;
            $this->audit($client, $id, 'name_match');
            $linked++;
        }

        return $linked;
    }

    /** Lower case, letters and digits only, with common company suffixes unified. */
    public static function normalise(string $name): string
    {
        $name = mb_strtolower($name);
        $name = preg_replace('/\bprivate\b/u', 'pvt', $name);
        $name = preg_replace('/\blimited\b/u', 'ltd', $name);
        $name = preg_replace('/[^\p{L}\p{N}]+/u', '', $name);

        return (string) $name;
    }

    private function audit(Client $client, string $customerId, string $how): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => 'client_zoho_link', 'subject_type' => 'Client', 'subject_id' => $client->id,
            'metadata' => ['zoho_contact_id' => $customerId, 'how' => $how],
            'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);
    }
}
