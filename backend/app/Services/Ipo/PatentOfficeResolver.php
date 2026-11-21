<?php

namespace App\Services\Ipo;

/** Maps an Indian state / UT to its Patent Office (Chennai, Mumbai, Delhi or Kolkata). */
class PatentOfficeResolver
{
    public function forState(?string $state): ?string
    {
        $key = $this->normalise($state);
        if ($key === '') {
            return null;
        }

        return config('ipo.state_office')[$key] ?? null;
    }

    public function isOffice(?string $office): bool
    {
        return in_array($office, config('ipo.offices'), true);
    }

    private function normalise(?string $state): string
    {
        $value = strtolower(trim((string) $state));
        $value = str_replace('&', ' and ', $value);
        $value = preg_replace('/[^a-z ]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }
}
