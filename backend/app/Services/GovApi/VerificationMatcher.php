<?php

namespace App\Services\GovApi;

use Carbon\Carbon;
use Illuminate\Support\Carbon as IlluminateCarbon;

/**
 * Shared comparison logic for cross-checking user-entered identity fields
 * (name, date of birth, address) against values a government verification
 * API returns, used by PanVerificationService, DigiLockerConsentService, and
 * EntityLockerVerificationService. Confirming an identifier (PAN/Aadhaar/
 * GSTIN/CIN) merely exists in the government DB is not sufficient — these
 * comparisons answer whether the record actually belongs to the person/
 * entity on file.
 */
class VerificationMatcher
{
    public const MATCH = 'Match';
    public const MISMATCH = 'Mismatch';
    public const UNKNOWN = 'Unknown';

    /**
     * Case/whitespace/punctuation-insensitive name comparison. Government
     * records often differ from user input only in casing, extra initials,
     * or spacing, so an exact string match would produce false mismatches.
     */
    public static function compareNames(?string $onFile, ?string $fetched): string
    {
        if (blank($onFile) || blank($fetched)) {
            return self::UNKNOWN;
        }

        return self::normalizeName($onFile) === self::normalizeName($fetched) ? self::MATCH : self::MISMATCH;
    }

    public static function compareDates(mixed $onFile, ?string $fetched): string
    {
        if (blank($onFile) || blank($fetched)) {
            return self::UNKNOWN;
        }

        $fetchedDate = self::parseDate($fetched);
        if (! $fetchedDate) {
            return self::UNKNOWN;
        }

        $onFileDate = $onFile instanceof Carbon || $onFile instanceof IlluminateCarbon
            ? $onFile
            : self::parseDate((string) $onFile);

        if (! $onFileDate) {
            return self::UNKNOWN;
        }

        return $onFileDate->isSameDay($fetchedDate) ? self::MATCH : self::MISMATCH;
    }

    /**
     * Looser comparison for addresses — government address strings rarely
     * match our free-text address field verbatim (different line breaks,
     * abbreviations, pin code placement), so this checks whether the
     * meaningful tokens of one appear within the other rather than
     * requiring equality.
     */
    public static function compareAddresses(?string $onFile, ?string $fetched): string
    {
        if (blank($onFile) || blank($fetched)) {
            return self::UNKNOWN;
        }

        $onFileTokens = self::addressTokens($onFile);
        $fetchedTokens = self::addressTokens($fetched);

        if ($onFileTokens === [] || $fetchedTokens === []) {
            return self::UNKNOWN;
        }

        $overlap = array_intersect($onFileTokens, $fetchedTokens);
        $overlapRatio = count($overlap) / min(count($onFileTokens), count($fetchedTokens));

        return $overlapRatio >= 0.6 ? self::MATCH : self::MISMATCH;
    }

    public static function parseDate(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd M Y', 'd-M-Y', 'Y/m/d'] as $format) {
            try {
                return Carbon::createFromFormat($format, trim($value))->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function normalizeName(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z\s]/', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }

    private static function addressTokens(string $address): array
    {
        $address = strtolower($address);
        $address = preg_replace('/[^a-z0-9\s]/', ' ', $address) ?? $address;
        $tokens = array_filter(preg_split('/\s+/', trim($address)) ?: [], fn (string $t) => strlen($t) >= 3);

        return array_values(array_unique($tokens));
    }
}
