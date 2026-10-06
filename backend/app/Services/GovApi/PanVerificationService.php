<?php

namespace App\Services\GovApi;

use App\Models\Employee;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PAN Verification via the Income Tax Department's API, consumed through
 * API Setu (apisetu.gov.in) — the free government channel, as distinct from
 * Protean/NSDL's paid commercial PAN OPV service. Confirms a PAN exists and
 * the registered name matches; does not fetch KYC documents (see
 * DigiLockerConsentService for that).
 *
 * The exact request/response field names are NOT hardcoded from public
 * documentation — API Setu issues the precise contract per approved consumer
 * request, which is not publicly standardized. This client uses the
 * conventional {pan, name, dob} request shape and a defensive, multi-key
 * response parse; adjust buildPayload()/parseResponse() once the real
 * approval documentation/Postman collection is in hand.
 */
class PanVerificationService
{
    private const SLUG = 'api_setu';

    public const STATUS_VERIFIED = 'Verified';
    public const STATUS_NAME_MISMATCH = 'NameMismatch';
    public const STATUS_FAILED = 'Failed';
    public const STATUS_UNVERIFIED = 'Unverified';

    public function isConfigured(): bool
    {
        $c = GovApiIntegrationConfig::get(self::SLUG);

        return ! empty($c['client_id']) && ! empty($c['client_secret']) && ! empty($c['base_url']) && ! empty($c['verify_path']);
    }

    /**
     * @return array{status: string, name_on_record: ?string, dob_on_record: ?string, message: string, matches: array{name: string, dob: string}, fetched: array{name: ?string, dob: ?string}, raw: array}
     */
    public function verify(Employee $employee): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('PAN Verification (API Setu) is not configured. Add credentials in Settings -> Integrations.');
        }
        if (empty($employee->pan_tax_id)) {
            throw new RuntimeException('This employee has no PAN on file to verify.');
        }

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
                    'pan' => $employee->pan_tax_id,
                    'name' => $employee->full_name,
                    'dob' => $employee->date_of_birth?->format('d/m/Y'),
                    'consent' => 'Y',
                ]);
        } catch (\Throwable $e) {
            Log::error('PanVerificationService: request failed', ['employee_id' => $employee->id, 'error' => $e->getMessage()]);
            $this->applyResult($employee, self::STATUS_FAILED, null, null);

            return $this->result(self::STATUS_FAILED, null, null, 'Could not reach the PAN verification service.', []);
        }

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            $this->applyResult($employee, self::STATUS_FAILED, null, null);

            return $this->result(self::STATUS_FAILED, null, null, $body['message'] ?? "Verification request failed (HTTP {$response->status()}).", $body);
        }

        [$exists, $nameOnRecord, $dobOnRecord] = $this->parseResponse($body);

        if (! $exists) {
            $this->applyResult($employee, self::STATUS_FAILED, null, null);

            return $this->result(self::STATUS_FAILED, null, null, 'PAN could not be verified against government records.', $body);
        }

        $nameMatch = VerificationMatcher::compareNames($employee->full_name, $nameOnRecord);
        $dobMatch = VerificationMatcher::compareDates($employee->date_of_birth, $dobOnRecord);

        // A mismatch on either field means the PAN exists but doesn't belong to who/what we have on file.
        $status = ($nameMatch === VerificationMatcher::MISMATCH || $dobMatch === VerificationMatcher::MISMATCH)
            ? self::STATUS_NAME_MISMATCH
            : self::STATUS_VERIFIED;

        $this->applyResult($employee, $status, $nameOnRecord, $dobOnRecord);

        $message = match (true) {
            $status === self::STATUS_VERIFIED => 'PAN verified — name and date of birth match government records.',
            $nameMatch === VerificationMatcher::MISMATCH && $dobMatch === VerificationMatcher::MISMATCH => "PAN exists but the registered name ({$nameOnRecord}) and date of birth ({$dobOnRecord}) do not match our records.",
            $nameMatch === VerificationMatcher::MISMATCH => "PAN exists but the registered name ({$nameOnRecord}) does not match our records.",
            default => "PAN exists but the registered date of birth ({$dobOnRecord}) does not match our records.",
        };

        return $this->result($status, $nameOnRecord, $dobOnRecord, $message, $body, ['name' => $nameMatch, 'dob' => $dobMatch]);
    }

    /**
     * @return array{status: string, name_on_record: ?string, dob_on_record: ?string, message: string, matches: array{name: string, dob: string}, fetched: array{name: ?string, dob: ?string}, raw: array}
     */
    private function result(string $status, ?string $nameOnRecord, ?string $dobOnRecord, string $message, array $raw, ?array $matches = null): array
    {
        return [
            'status' => $status,
            'name_on_record' => $nameOnRecord,
            'dob_on_record' => $dobOnRecord,
            'message' => $message,
            'matches' => $matches ?? ['name' => VerificationMatcher::UNKNOWN, 'dob' => VerificationMatcher::UNKNOWN],
            'fetched' => ['name' => $nameOnRecord, 'dob' => $dobOnRecord],
            'raw' => $raw,
        ];
    }

    /**
     * Defensive parse — tries several plausible key spellings since the exact
     * response contract is only known once API Setu's approval documentation
     * is in hand. Logs the full raw body either way so the real shape is
     * visible in storage/logs on the first live call.
     *
     * @return array{0: bool, 1: ?string, 2: ?string} [exists, name_on_record, dob_on_record]
     */
    private function parseResponse(array $body): array
    {
        Log::info('PanVerificationService: raw response', ['body' => $body]);

        $panExists = $body['pan_status'] ?? $body['status'] ?? $body['panStatus'] ?? null;
        $nameOnRecord = $body['name_on_record'] ?? $body['registered_name'] ?? $body['name'] ?? null;
        $dobOnRecord = $body['dob_on_record'] ?? $body['registered_dob'] ?? $body['dob'] ?? null;

        $exists = is_string($panExists) ? in_array(strtolower($panExists), ['valid', 'active', 'e', 'existing'], true) : (bool) $panExists;

        return [$exists, $nameOnRecord, $dobOnRecord];
    }

    private function applyResult(Employee $employee, string $status, ?string $nameOnRecord, ?string $dobOnRecord): void
    {
        $employee->update([
            'pan_verification_status' => $status,
            'pan_verified_at' => now(),
            'pan_verified_name' => $nameOnRecord,
            'pan_verified_dob' => $dobOnRecord ? VerificationMatcher::parseDate($dobOnRecord) : null,
        ]);
    }

}
