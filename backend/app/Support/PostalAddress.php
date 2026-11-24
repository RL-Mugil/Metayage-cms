<?php

namespace App\Support;

/**
 * A client's postal address in the same parts the IPO forms use: house / building,
 * street / area, city, state, country, pincode. compose() builds the one-line address
 * kept in clients.address (read by the GSTIN/CIN address check and older screens);
 * split() is a best-effort parse of an old one-line address into those parts.
 */
class PostalAddress
{
    public const PARTS = ['address_house', 'address_street', 'city', 'state', 'country', 'pincode'];

    private const STREET_WORDS = '/\b(road|rd|street|st\.|lane|ln|cross|main|floor|flr|block|sector|phase|layout|stage|plot|no\.?|door|flat|building|bldg|tower|apartment|apartments|complex|opp\.?|near|behind|post|p\.?o\.?|village|taluk)\b/i';

    private const INDIAN_STATES = [
        'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh',
        'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha',
        'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
        'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir',
        'Ladakh', 'Lakshadweep', 'Puducherry', 'New Delhi', 'Orissa', 'Pondicherry',
    ];

    /** @param array<string, mixed> $parts */
    public static function compose(array $parts): string
    {
        $line = collect(['address_house', 'address_street', 'city', 'state', 'country'])
            ->map(fn ($k) => trim((string) ($parts[$k] ?? '')))
            ->filter()->implode(', ');
        $pin = trim((string) ($parts['pincode'] ?? ''));

        return trim($line.($pin !== '' ? ($line !== '' ? ' - ' : '').$pin : ''));
    }

    public static function isIndia(?string $country): bool
    {
        return in_array(strtolower(trim((string) $country)), ['', 'india', 'in', 'ind', 'bharat'], true);
    }

    /**
     * @return array{address_house: string, address_street: string, city: string, state: string, country: string, pincode: string, confidence: string}
     */
    public static function split(?string $text, ?string $state = null, ?string $country = null): array
    {
        $out = ['address_house' => '', 'address_street' => '', 'city' => '', 'state' => trim((string) $state), 'country' => trim((string) $country), 'pincode' => '', 'confidence' => 'partial'];
        $text = trim(preg_replace('/\s+/u', ' ', str_replace(["\r\n", "\n", "\r", ';'], ', ', (string) $text)));
        if ($text === '') {
            return $out;
        }
        $india = self::isIndia($country);

        // Pincode: the last 6-digit number (also "560 033") for India; a trailing postal code abroad.
        if ($india && preg_match_all('/(?<!\d)(\d{3})\s?(\d{3})(?!\d)/', $text, $m, PREG_OFFSET_CAPTURE)) {
            $last = count($m[0]) - 1;
            $out['pincode'] = $m[1][$last][0].$m[2][$last][0];
            $text = substr_replace($text, ',', $m[0][$last][1], strlen($m[0][$last][0]));
        } elseif (! $india) {
            // Numeric ZIP / postcode (incl. ZIP+4), UK postcode, Canadian postal code — at the end.
            foreach (['/[\s,](\d{3,6}(?:-\d{3,4})?)\s*$/', '/[\s,]([A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2})\s*$/i', '/[\s,]([A-Z]\d[A-Z]\s?\d[A-Z]\d)\s*$/i'] as $re) {
                if (preg_match($re, $text, $m)) {
                    $out['pincode'] = strtoupper(trim($m[1]));
                    $text = substr($text, 0, -strlen($m[0]));
                    break;
                }
            }
        }

        $parts = collect(explode(',', $text))
            ->map(fn ($p) => trim($p, " \t-–—.:"))
            ->map(fn ($p) => preg_replace('/\b(pin(code)?|pin code|zip)\s*[:\-]?\s*$/i', '', $p))
            ->map(fn ($p) => trim((string) $p, " \t-–—.:"))
            ->filter(fn ($p) => $p !== '')->values();

        // Drop country and state parts (keeping what they name when not already known).
        $countryNames = $india ? ['india', 'bharat'] : [strtolower($out['country'])];
        $parts = $parts->reject(function ($p) use ($countryNames, &$out, $india) {
            $l = strtolower($p);
            if (in_array($l, $countryNames, true)) {
                return true;
            }
            if ($india) {
                foreach (self::INDIAN_STATES as $st) {
                    if ($l === strtolower($st)) {
                        $out['state'] = $out['state'] ?: $st;

                        return true;
                    }
                }
            } elseif ($out['state'] !== '' && $l === strtolower($out['state'])) {
                return true;
            }

            return false;
        })->values();

        // City: the last remaining part, unless it reads like a street line.
        if ($parts->isNotEmpty()) {
            $candidate = preg_replace('/\s*(dist(rict)?\.?|urban|rural)$/i', '', (string) $parts->last());
            if ($candidate !== '' && ! preg_match('/\d/', $candidate) && ! preg_match(self::STREET_WORDS, $candidate) && mb_strlen($candidate) <= 40) {
                $out['city'] = trim($candidate);
                $parts->pop();
            }
        }

        // House: the first part when it looks like a door / building number (with the next part if it is only a number).
        if ($parts->count() > 1 && preg_match('/^(no\.?|#|flat|door|plot|h\.?\s?no\.?|\d)/i', (string) $parts->first())) {
            $house = (string) $parts->shift();
            if (mb_strlen($house) <= 6 && $parts->count() > 1) {
                $house .= ', '.$parts->shift();
            }
            $out['address_house'] = $house;
        }
        $out['address_street'] = $parts->implode(', ');

        $out['confidence'] = ($out['city'] !== '' && ($out['address_street'] !== '' || $out['address_house'] !== '') && (! $india || ($out['pincode'] !== '' && $out['state'] !== '')))
            ? 'clean' : 'partial';

        return $out;
    }
}
