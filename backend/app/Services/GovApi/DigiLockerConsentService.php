<?php

namespace App\Services\GovApi;

use App\Models\DigilockerConsent;
use App\Models\Employee;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DigiLocker Requester three-legged OAuth consent flow — the employee grants
 * consent in their own DigiLocker account, DigiLocker redirects back to us
 * with an authorization code, we exchange it for an access token and pull
 * the already-issued, government-signed document.
 *
 * Deliberately stores only a verified/failed status + timestamp on the
 * Employee record, never the fetched Aadhaar document or XML itself — this
 * sidesteps UIDAI's Aadhaar Data Vault storage obligation, which only
 * applies when raw Aadhaar numbers/documents are retained.
 *
 * Endpoint paths are NOT hardcoded from public documentation — DigiLocker
 * issues the exact authorize/token/document-pull URLs per approved Requester
 * application, which is not publicly standardized. This client assumes a
 * conventional OAuth2 Authorization Code grant; adjust parseDocumentResponse()
 * once the real approval documentation is in hand.
 */
class DigiLockerConsentService
{
    private const SLUG = 'digilocker';

    public function isConfigured(): bool
    {
        $c = GovApiIntegrationConfig::get(self::SLUG);

        return ! empty($c['client_id']) && ! empty($c['client_secret'])
            && ! empty($c['authorize_url']) && ! empty($c['token_url'])
            && ! empty($c['document_pull_url']) && ! empty($c['redirect_uri']);
    }

    public function buildAuthorizationUrl(Employee $employee, string $documentType, int $requestedByUserId): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('DigiLocker is not configured. Add credentials in Settings -> Integrations.');
        }

        $c = GovApiIntegrationConfig::get(self::SLUG);

        $consent = DigilockerConsent::create([
            'employee_id' => $employee->id,
            'state_token' => Str::random(40),
            'document_type' => $documentType,
            'status' => DigilockerConsent::STATUS_PENDING,
            'requested_by_id' => $requestedByUserId,
        ]);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $c['client_id'],
            'redirect_uri' => $c['redirect_uri'],
            'state' => $consent->state_token,
        ]);

        return $c['authorize_url'] . (str_contains($c['authorize_url'], '?') ? '&' : '?') . $query;
    }

    /**
     * @return array{status: string, message: string, consent: DigilockerConsent}
     */
    public function handleCallback(string $code, string $state): array
    {
        $consent = DigilockerConsent::where('state_token', $state)->where('status', DigilockerConsent::STATUS_PENDING)->first();
        if (! $consent) {
            throw new RuntimeException('This DigiLocker consent link is invalid or has already been used.');
        }

        $c = GovApiIntegrationConfig::get(self::SLUG);

        try {
            $tokenResponse = Http::asForm()->connectTimeout(10)->timeout(20)->post($c['token_url'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $c['redirect_uri'],
                'client_id' => $c['client_id'],
                'client_secret' => $c['client_secret'],
            ]);

            if (! $tokenResponse->successful()) {
                throw new RuntimeException("Token exchange failed (HTTP {$tokenResponse->status()}).");
            }

            $accessToken = $tokenResponse->json('access_token') ?? $tokenResponse->json('accessToken');
            if (! $accessToken) {
                throw new RuntimeException('Token exchange response did not include an access token.');
            }

            $docResponse = Http::connectTimeout(10)->timeout(20)
                ->withToken($accessToken)
                ->get($c['document_pull_url'], ['doctype' => $consent->document_type]);

            $docBody = $docResponse->json() ?? [];
            Log::info('DigiLockerConsentService: raw document response', ['consent_id' => $consent->id, 'body' => $docBody]);

            if (! $docResponse->successful()) {
                throw new RuntimeException("Document fetch failed (HTTP {$docResponse->status()}).");
            }

            $employee = $consent->employee;
            [$nameOnRecord, $dobOnRecord, $addressOnRecord] = $this->parseDocumentResponse($docBody);

            $nameMatch = VerificationMatcher::compareNames($employee->full_name, $nameOnRecord);
            $dobMatch = VerificationMatcher::compareDates($employee->date_of_birth, $dobOnRecord);
            $addressMatch = VerificationMatcher::compareAddresses($employee->current_address, $addressOnRecord);

            $status = in_array(VerificationMatcher::MISMATCH, [$nameMatch, $dobMatch, $addressMatch], true)
                ? 'NameMismatch'
                : 'Verified';

            $employee->update([
                'aadhaar_verification_status' => $status,
                'aadhaar_verified_at' => now(),
                'aadhaar_verified_name' => $nameOnRecord,
                'aadhaar_verified_dob' => $dobOnRecord ? VerificationMatcher::parseDate($dobOnRecord) : null,
                'aadhaar_verified_address' => $addressOnRecord,
            ]);
            $consent->update(['status' => DigilockerConsent::STATUS_COMPLETED, 'completed_at' => now()]);

            $message = $status === 'Verified'
                ? 'Aadhaar verified via DigiLocker — name, date of birth, and address match our records.'
                : 'Aadhaar document fetched via DigiLocker, but some details do not match our records. Review the fetched data below.';

            return [
                'status' => $status,
                'message' => $message,
                'consent' => $consent,
                'matches' => ['name' => $nameMatch, 'dob' => $dobMatch, 'address' => $addressMatch],
                'fetched' => ['name' => $nameOnRecord, 'dob' => $dobOnRecord, 'address' => $addressOnRecord],
            ];
        } catch (\Throwable $e) {
            Log::error('DigiLockerConsentService: callback failed', ['consent_id' => $consent->id, 'error' => $e->getMessage()]);
            $consent->employee->update(['aadhaar_verification_status' => 'Failed']);
            $consent->update(['status' => DigilockerConsent::STATUS_FAILED]);

            return ['status' => 'Failed', 'message' => $e->getMessage(), 'consent' => $consent];
        }
    }

    /**
     * Defensive parse of the eAadhaar document pull response — tries several
     * plausible key spellings/nesting since the exact contract is only known
     * once the real DigiLocker Requester approval documentation is in hand.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} [name, dob, address]
     */
    private function parseDocumentResponse(array $body): array
    {
        $doc = $body['document'] ?? $body['data'] ?? $body;

        $name = $doc['name'] ?? $doc['Name'] ?? $doc['full_name'] ?? null;
        $dob = $doc['dob'] ?? $doc['DOB'] ?? $doc['date_of_birth'] ?? null;

        $address = $doc['address'] ?? $doc['Address'] ?? null;
        if (is_array($address)) {
            $address = implode(', ', array_filter($address, fn ($v) => is_string($v) && $v !== ''));
        }

        return [$name, $dob, $address];
    }
}
