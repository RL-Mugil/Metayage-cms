<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

class DocketNumberService
{
    public const INVENTION_LENGTH = 3;
    public const OFFICE_LENGTH = 2;
    public const SERVICE_LENGTH = 3;

    public function assignForCreation(array $attributes): array
    {
        $client = Client::query()->lockForUpdate()->findOrFail($attributes['client_id']);
        $clientCode = $this->clientCode($client);
        $projectCode = strtoupper(trim((string) ($attributes['project_code'] ?? '')));
        $docketNumber = strtoupper(trim((string) ($attributes['docket_number'] ?? '')));
        if ($projectCode !== '' && $docketNumber !== '' && $projectCode !== $docketNumber) {
            throw ValidationException::withMessages([
                'project_code' => 'Project code, UIN, and docket number must be identical.',
                'docket_number' => 'Project code, UIN, and docket number must be identical.',
            ]);
        }
        $supplied = $projectCode !== '' ? $projectCode : $docketNumber;

        if ($supplied !== '') {
            $parts = $this->parse($supplied, $clientCode);
            $this->assertMatchesSuppliedParts($attributes, $parts);
        } else {
            $parts = [
                'invention_number' => $this->nextInventionNumber($client),
                'patent_office_code' => $this->officeCode($attributes['patent_office_code'] ?? null),
                'service_code' => $this->serviceCode($attributes['service_code'] ?? null),
            ];
        }

        $canonical = $this->compose(
            $clientCode,
            $parts['invention_number'],
            $parts['patent_office_code'],
            $parts['service_code'],
        );

        if (Project::withTrashed()->where(fn ($query) => $query
            ->where('project_code', $canonical)
            ->orWhere('docket_number', $canonical))->exists()) {
            throw ValidationException::withMessages(['docket_number' => 'This canonical docket number already exists.']);
        }

        return array_merge($attributes, $parts, [
            'project_code' => $canonical,
            'docket_number' => $canonical,
        ]);
    }

    public function recanonicalize(Project $project, ?string $office = null, ?string $service = null): array
    {
        $clientCode = $this->clientCode($project->client()->firstOrFail());
        $invention = $project->invention_number;

        if (! $invention) {
            $invention = $this->parse((string) $project->docket_number, $clientCode)['invention_number'];
        }

        $parts = [
            'invention_number' => $this->inventionNumber($invention),
            'patent_office_code' => $this->officeCode($office ?? $project->patent_office_code),
            'service_code' => $this->serviceCode($service ?? $project->service_code),
        ];
        $canonical = $this->compose($clientCode, ...array_values($parts));

        $duplicate = Project::withTrashed()
            ->where('id', '!=', $project->id)
            ->where(fn ($query) => $query->where('project_code', $canonical)->orWhere('docket_number', $canonical))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['docket_number' => 'The resulting canonical docket number already exists.']);
        }

        return array_merge($parts, ['project_code' => $canonical, 'docket_number' => $canonical]);
    }

    public function parse(string $docket, string $clientCode): array
    {
        $canonical = strtoupper(trim($docket));
        $prefix = strtoupper(trim($clientCode));
        $suffixLength = self::INVENTION_LENGTH + self::OFFICE_LENGTH + self::SERVICE_LENGTH;

        if (! str_starts_with($canonical, $prefix) || strlen($canonical) !== strlen($prefix) + $suffixLength) {
            throw ValidationException::withMessages([
                'docket_number' => "Docket must be {$prefix} + 3-digit invention number + 2-character office code + 3-character service code.",
            ]);
        }

        $offset = strlen($prefix);

        return [
            'invention_number' => $this->inventionNumber(substr($canonical, $offset, 3)),
            'patent_office_code' => $this->officeCode(substr($canonical, $offset + 3, 2)),
            'service_code' => $this->serviceCode(substr($canonical, $offset + 5, 3)),
        ];
    }

    public function compose(string $clientCode, string $invention, string $office, string $service): string
    {
        return $this->normalizeClientCode($clientCode)
            .$this->inventionNumber($invention)
            .$this->officeCode($office)
            .$this->serviceCode($service);
    }

    private function nextInventionNumber(Client $client): string
    {
        $max = Project::withTrashed()
            ->where('client_id', $client->id)
            ->pluck('invention_number')
            ->filter(fn ($number) => is_string($number) && preg_match('/^\d{3}$/', $number))
            ->map(fn ($number) => (int) $number)
            ->max() ?? 0;

        if ($max >= 999) {
            throw ValidationException::withMessages(['docket_number' => 'This client has exhausted the 3-digit invention-number range.']);
        }

        return str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    private function assertMatchesSuppliedParts(array $attributes, array $parts): void
    {
        foreach (['patent_office_code', 'service_code'] as $field) {
            if (! empty($attributes[$field]) && strtoupper(trim((string) $attributes[$field])) !== $parts[$field]) {
                throw ValidationException::withMessages([$field => "The selected {$field} does not match the supplied docket number."]);
            }
        }
    }

    private function clientCode(Client $client): string
    {
        if (! $client->client_code) {
            throw ValidationException::withMessages(['client_id' => 'The selected client has no client code.']);
        }

        return $this->normalizeClientCode($client->client_code);
    }

    private function normalizeClientCode(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '' || ! preg_match('/^[A-Z0-9]+$/', $value)) {
            throw ValidationException::withMessages(['client_id' => 'Client code must contain only letters and numbers.']);
        }
        return $value;
    }

    private function inventionNumber(?string $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{3}$/', $value) || $value === '000') {
            throw ValidationException::withMessages(['invention_number' => 'Invention number must be from 001 to 999.']);
        }
        return $value;
    }

    private function officeCode(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        if (! preg_match('/^[A-Z0-9]{2}$/', $value)) {
            throw ValidationException::withMessages(['patent_office_code' => 'Patent office code must be exactly 2 letters or numbers.']);
        }
        return $value;
    }

    private function serviceCode(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        if (! preg_match('/^[A-Z0-9]{3}$/', $value)) {
            throw ValidationException::withMessages(['service_code' => 'Service code must be exactly 3 letters or numbers.']);
        }
        return $value;
    }
}
