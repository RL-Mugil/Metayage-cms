<?php

namespace App\Services\GovApi;

use App\Models\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Verifies a corporate client's registered business documents (GST
 * registration, Certificate of Incorporation) against government records via
 * EntityLocker, consumed through API Setu. Same shape as PanVerificationService
 * — see that class's docblock for why endpoint paths are configurable rather
 * than hardcoded (per-consumer, approval-issued URLs, not publicly standardized).
 */
class EntityLockerVerificationService
{
    private const SLUG = 'entitylocker';

    public const STATUS_VERIFIED = 'Verified';
    public const STATUS_MISMATCH = 'Mismatch';
    public const STATUS_FAILED = 'Failed';

    public function isConfigured(): bool
    {
        $c = GovApiIntegrationConfig::get(self::SLUG);

        return ! empty($c['client_id']) && ! empty($c['client_secret']) && ! empty($c['base_url']) && ! empty($c['verify_path']);
    }

    /**
     * @return array{gstin: ?array, cin: ?array}
     */
    public function verify(Client $client): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('EntityLocker is not configured. Add credentials in Settings -> Integrations.');
        }

        $results = [];

        if (! empty($client->gstin)) {
            $results['gstin'] = $this->verifyDocument($client, 'GSTIN', $client->gstin);
            $client->update([
                'gstin_verification_status' => $results['gstin']['status'],
                'gstin_verified_at' => now(),
                'gstin_verified_name' => $results['gstin']['fetched']['name'],
                'gstin_verified_address' => $results['gstin']['fetched']['address'],
            ]);
        }

        if (! empty($client->cin_number)) {
            $results['cin'] = $this->verifyDocument($client, 'CIN', $client->cin_number);
            $client->update([
                'cin_verification_status' => $results['cin']['status'],
                'cin_verified_at' => now(),
                'cin_verified_name' => $results['cin']['fetched']['name'],
                'cin_verified_address' => $results['cin']['fetched']['address'],
            ]);
        }

        if (empty($results)) {
            throw new RuntimeException('This client has no GSTIN or CIN on file to verify.');
        }

        return $results;
    }

    private function verifyDocument(Client $client, string $docType, string $number): array
    {
        $c = GovApiIntegrationConfig::get(self::SLUG);
        $url = $c['base_url'] . $c['verify_path'];

        try {
            $response = Http::connectTimeout(10)->timeout(20)
                ->withHeaders([
                    'X-Client-Id' => $c['client_id'],
                    'Authorization' => 'Bearer ' . $c['client_secret'],
                    'Accept' => 'application/json',
                ])
                ->post($url, [
                    'document_type' => $docType,
                    'document_number' => $number,
                    'entity_name' => $client->legal_name ?? $client->company_name,
                ]);
        } catch (\Throwable $e) {
            Log::error('EntityLockerVerificationService: request failed', ['client_id' => $client->id, 'doc_type' => $docType, 'error' => $e->getMessage()]);

            return $this->docResult(self::STATUS_FAILED, 'Could not reach the EntityLocker verification service.', null, null);
        }

        $body = $response->json() ?? [];
        Log::info('EntityLockerVerificationService: raw response', ['client_id' => $client->id, 'doc_type' => $docType, 'body' => $body]);

        if (! $response->successful()) {
            return $this->docResult(self::STATUS_FAILED, $body['message'] ?? "Verification failed (HTTP {$response->status()}).", null, null);
        }

        $exists = $body['status'] ?? $body['document_status'] ?? $body['valid'] ?? null;
        $nameOnRecord = $body['entity_name'] ?? $body['registered_name'] ?? $body['name'] ?? null;
        $addressOnRecord = $body['address'] ?? $body['registered_address'] ?? null;
        if (is_array($addressOnRecord)) {
            $addressOnRecord = implode(', ', array_filter($addressOnRecord, fn ($v) => is_string($v) && $v !== ''));
        }

        $isValid = is_string($exists) ? in_array(strtolower($exists), ['valid', 'active', 'found'], true) : (bool) $exists;

        if (! $isValid) {
            return $this->docResult(self::STATUS_FAILED, "{$docType} could not be verified against government records.", $nameOnRecord, $addressOnRecord);
        }

        $nameMatch = VerificationMatcher::compareNames($client->legal_name ?? $client->company_name, $nameOnRecord);
        $addressMatch = VerificationMatcher::compareAddresses($client->address, $addressOnRecord);

        if ($nameMatch === VerificationMatcher::MISMATCH || $addressMatch === VerificationMatcher::MISMATCH) {
            $detail = $nameMatch === VerificationMatcher::MISMATCH && $addressMatch === VerificationMatcher::MISMATCH
                ? 'registered name and address do'
                : ($nameMatch === VerificationMatcher::MISMATCH ? 'registered name does' : 'registered address does');

            return $this->docResult(self::STATUS_MISMATCH, "{$docType} exists but the {$detail} not match our records.", $nameOnRecord, $addressOnRecord, $nameMatch, $addressMatch);
        }

        return $this->docResult(self::STATUS_VERIFIED, "{$docType} verified — name and address match government records.", $nameOnRecord, $addressOnRecord, $nameMatch, $addressMatch);
    }

    private function docResult(string $status, string $message, ?string $nameOnRecord, ?string $addressOnRecord, ?string $nameMatch = null, ?string $addressMatch = null): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'matches' => ['name' => $nameMatch ?? VerificationMatcher::UNKNOWN, 'address' => $addressMatch ?? VerificationMatcher::UNKNOWN],
            'fetched' => ['name' => $nameOnRecord, 'address' => $addressOnRecord],
        ];
    }
}
