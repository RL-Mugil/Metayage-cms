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

    private const STREET_WORDS = '/\b(road|rd|street|st\.|lane|ln|cross|main|floor|flr|block|sector|phase|layout|stage|plot|no\.?|door|flat|building|bldg|tower|apartment|apartments|complex|opp\.?|near|behind|post|p\.?o\.?|village|taluk|suite|ste|unit|avenue|ave|drive|dr|boulevard|blvd|highway|hwy)\b/i';

    /** Separators and dashes trimmed from each part (multibyte-safe; trim() would split “ and other UTF-8 characters). */
    private const EDGE = '/^[\s\-–—.:]+|[\s\-–—.:]+$/u';

    private const INDIAN_STATES = [
        'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh',
        'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha',
        'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
        'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir',
        'Ladakh', 'Lakshadweep', 'Puducherry', 'New Delhi', 'Orissa', 'Pondicherry',
    ];

    /** Vehicle / postal abbreviations seen in addresses ("Hyderabad TG", "Chennai TN"). */
    private const INDIAN_STATE_CODES = [
        'AP' => 'Andhra Pradesh', 'AR' => 'Arunachal Pradesh', 'AS' => 'Assam', 'BR' => 'Bihar', 'CG' => 'Chhattisgarh', 'GA' => 'Goa',
        'GJ' => 'Gujarat', 'HR' => 'Haryana', 'HP' => 'Himachal Pradesh', 'JH' => 'Jharkhand', 'KA' => 'Karnataka', 'KL' => 'Kerala',
        'MP' => 'Madhya Pradesh', 'MH' => 'Maharashtra', 'MN' => 'Manipur', 'ML' => 'Meghalaya', 'MZ' => 'Mizoram', 'NL' => 'Nagaland',
        'OD' => 'Odisha', 'OR' => 'Odisha', 'PB' => 'Punjab', 'RJ' => 'Rajasthan', 'SK' => 'Sikkim', 'TN' => 'Tamil Nadu',
        'TG' => 'Telangana', 'TS' => 'Telangana', 'TR' => 'Tripura', 'UP' => 'Uttar Pradesh', 'UK' => 'Uttarakhand', 'UA' => 'Uttarakhand',
        'WB' => 'West Bengal', 'DL' => 'Delhi', 'CH' => 'Chandigarh', 'JK' => 'Jammu and Kashmir', 'PY' => 'Puducherry',
    ];

    /** Common Indian cities, used only when a one-line address has no commas before the PIN. */
    private const INDIAN_CITIES = [
        'Bengaluru', 'Bangalore', 'Chennai', 'Hyderabad', 'Secunderabad', 'Mumbai', 'Navi Mumbai', 'Thane', 'Pune', 'New Delhi', 'Delhi',
        'Kolkata', 'Coimbatore', 'Ahmedabad', 'Gurgaon', 'Gurugram', 'Noida', 'Greater Noida', 'Jaipur', 'Lucknow', 'Kochi', 'Cochin',
        'Thiruvananthapuram', 'Trivandrum', 'Madurai', 'Tiruchirappalli', 'Trichy', 'Salem', 'Erode', 'Tiruppur', 'Vellore', 'Mysuru',
        'Mysore', 'Mangaluru', 'Mangalore', 'Hubli', 'Belagavi', 'Visakhapatnam', 'Vijayawada', 'Nagpur', 'Nashik', 'Indore', 'Bhopal',
        'Chandigarh', 'Mohali', 'Ludhiana', 'Vadodara', 'Surat', 'Rajkot', 'Bhubaneswar', 'Guwahati', 'Patna', 'Ranchi', 'Dehradun',
        'Puducherry', 'Pondicherry', 'Kanpur', 'Faridabad', 'Ghaziabad',
    ];

    private const US_STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado',
        'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
        'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
        'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey',
        'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
        'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
        'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'PR' => 'Puerto Rico',
    ];

    private const CA_PROVINCES = [
        'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'NB' => 'New Brunswick', 'NL' => 'Newfoundland and Labrador',
        'NS' => 'Nova Scotia', 'NT' => 'Northwest Territories', 'NU' => 'Nunavut', 'ON' => 'Ontario', 'PE' => 'Prince Edward Island',
        'QC' => 'Quebec', 'SK' => 'Saskatchewan', 'YT' => 'Yukon',
    ];

    private const AU_STATES = [
        'NSW' => 'New South Wales', 'VIC' => 'Victoria', 'QLD' => 'Queensland', 'WA' => 'Western Australia', 'SA' => 'South Australia',
        'TAS' => 'Tasmania', 'ACT' => 'Australian Capital Territory', 'NT' => 'Northern Territory',
    ];

    /** Country code => names written in addresses. */
    private const COUNTRY_NAMES = [
        'IN' => ['india', 'bharat', 'in', 'ind'],
        'US' => ['usa', 'u.s.a', 'u.s.a.', 'us', 'u.s.', 'united states', 'united states of america', 'america'],
        'SG' => ['singapore', 'sg', 'republic of singapore'],
        'GB' => ['uk', 'u.k.', 'united kingdom', 'gb', 'great britain', 'england', 'scotland', 'wales'],
        'CA' => ['canada', 'ca'],
        'AU' => ['australia', 'au'],
        'AE' => ['uae', 'u.a.e.', 'united arab emirates', 'ae'],
        'NZ' => ['new zealand', 'nz'],
        'FR' => ['france', 'fr'], 'DE' => ['germany', 'de', 'deutschland'], 'NL' => ['netherlands', 'the netherlands', 'nl'],
        'SE' => ['sweden', 'se'], 'FI' => ['finland', 'fi'], 'ES' => ['spain', 'es'], 'JP' => ['japan', 'jp'], 'CN' => ['china', 'cn'],
    ];

    /** Postal code at the end of the line, per country (generic numeric otherwise). */
    private const POSTCODE = [
        'US' => '\d{5}(?:-\d{4})?',
        'SG' => '\d{6}',
        'GB' => '[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}',
        'CA' => '[A-Z]\d[A-Z]\s?\d[A-Z]\d',
        'AU' => '\d{4}',
        'NZ' => '\d{4}',
        'JP' => '\d{3}-?\d{4}',
    ];

    /** Countries whose addresses carry a state / province, so a clean split needs one. */
    private const NEEDS_STATE = ['IN', 'US', 'CA', 'AU'];

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

    /** ISO-style code for a stored country value ("US", "USA", "United States" → US); '' when unknown. */
    public static function countryCode(?string $country): string
    {
        $c = strtolower(trim((string) $country));
        if (self::isIndia($c)) {
            return 'IN';
        }
        if ($c === 'uk') {
            return 'GB';
        }
        foreach (self::COUNTRY_NAMES as $code => $names) {
            if ($c === strtolower($code) || in_array($c, $names, true)) {
                return $code;
            }
        }

        return strtoupper($c);
    }

    /** Indian state for a 6-digit PIN code (by its leading digits); '' when not certain. */
    public static function indianStateFromPin(string $pin): string
    {
        if (! preg_match('/^[1-9]\d{5}$/', $pin)) {
            return '';
        }
        $p2 = (int) substr($pin, 0, 2);
        $p3 = (int) substr($pin, 0, 3);
        $p4 = (int) substr($pin, 0, 4);

        $three = [
            160 => 'Chandigarh', 194 => 'Ladakh', 246 => 'Uttarakhand', 248 => 'Uttarakhand', 249 => 'Uttarakhand', 263 => 'Uttarakhand',
            403 => 'Goa', 737 => 'Sikkim', 744 => 'Andaman and Nicobar Islands',
            790 => 'Arunachal Pradesh', 791 => 'Arunachal Pradesh', 792 => 'Arunachal Pradesh', 793 => 'Meghalaya', 794 => 'Meghalaya',
            795 => 'Manipur', 796 => 'Mizoram', 797 => 'Nagaland', 798 => 'Nagaland', 799 => 'Tripura',
            814 => 'Jharkhand', 815 => 'Jharkhand', 816 => 'Jharkhand', 822 => 'Jharkhand', 825 => 'Jharkhand', 826 => 'Jharkhand',
            827 => 'Jharkhand', 828 => 'Jharkhand', 829 => 'Jharkhand', 831 => 'Jharkhand', 832 => 'Jharkhand', 833 => 'Jharkhand',
            834 => 'Jharkhand', 835 => 'Jharkhand',
        ];
        if (in_array($p4, [6050, 6051], true)) {
            return 'Puducherry';
        }
        if (isset($three[$p3])) {
            return $three[$p3];
        }

        return match (true) {
            $p2 === 11 => 'Delhi',
            $p2 >= 12 && $p2 <= 13 => 'Haryana',
            $p2 >= 14 && $p2 <= 16 => 'Punjab',
            $p2 === 17 => 'Himachal Pradesh',
            $p2 >= 18 && $p2 <= 19 => 'Jammu and Kashmir',
            $p2 >= 20 && $p2 <= 28 => 'Uttar Pradesh',
            $p2 >= 30 && $p2 <= 34 => 'Rajasthan',
            $p2 >= 36 && $p2 <= 39 => 'Gujarat',
            $p2 >= 40 && $p2 <= 44 => 'Maharashtra',
            $p2 >= 45 && $p2 <= 48 => 'Madhya Pradesh',
            $p2 === 49 => 'Chhattisgarh',
            $p2 === 50 => 'Telangana',
            $p2 >= 51 && $p2 <= 53 => 'Andhra Pradesh',
            $p2 >= 56 && $p2 <= 59 => 'Karnataka',
            $p2 >= 60 && $p2 <= 64 => 'Tamil Nadu',
            $p2 >= 67 && $p2 <= 69 => 'Kerala',
            $p2 >= 70 && $p2 <= 74 => 'West Bengal',
            $p2 >= 75 && $p2 <= 77 => 'Odisha',
            $p2 === 78 => 'Assam',
            $p2 >= 80 && $p2 <= 85 => 'Bihar',
            default => '',
        };
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
        $code = self::countryCode($country);
        if (! isset(self::COUNTRY_NAMES[$code])) {
            // Unknown stored country (e.g. "EP"): take it from a full country name at the end of the line.
            foreach (self::COUNTRY_NAMES as $known => $names) {
                foreach ($names as $name) {
                    if (mb_strlen($name) >= 3 && preg_match('/(^|[\s,\-])'.preg_quote($name, '/').'\.?([\s,\-]+[A-Z0-9][A-Z0-9 \-]{1,9})?[\s,]*$/iu', $text)) {
                        $code = $known;
                        break 2;
                    }
                }
            }
        }
        $india = $code === 'IN';
        $countryNames = self::COUNTRY_NAMES[$code] ?? [strtolower($out['country'])];
        $strippedCountry = false;

        // A trailing country name ("…, USA", "… SINGAPORE").
        $stripCountry = function () use (&$text, $countryNames, &$strippedCountry) {
            foreach ($countryNames as $name) {
                $re = '/(^|[\s,\-])'.preg_quote($name, '/').'\.?[\s,\-]*$/iu';
                if ($name !== '' && preg_match($re, $text)) {
                    $text = rtrim((string) preg_replace($re, '', $text), " ,-");
                    $strippedCountry = true;

                    return;
                }
            }
        };
        $stripCountry();

        if ($india) {
            // Pincode: the last 6-digit number (also "560 033").
            if (preg_match_all('/(?<!\d)([1-9]\d{2})\s?(\d{3})(?!\d)/', $text, $m, PREG_OFFSET_CAPTURE)) {
                $last = count($m[0]) - 1;
                $out['pincode'] = $m[1][$last][0].$m[2][$last][0];
                $text = substr_replace($text, ',', $m[0][$last][1], strlen($m[0][$last][0]));
            }
        } else {
            $re = '/(?:^|[\s,\-])('.(self::POSTCODE[$code] ?? '\d{3,6}(?:-\d{3,4})?').')[\s,]*$/i';
            if (preg_match($re, $text, $m)) {
                $out['pincode'] = strtoupper(preg_replace('/\s+/', ' ', trim($m[1])));
                $text = rtrim(substr($text, 0, -strlen($m[0])), " ,-");
                $stripCountry();   // "…, USA 98029" / "… SINGAPORE -069534"
            }
        }

        $parts = collect(explode(',', $text))
            ->map(fn ($p) => (string) preg_replace(self::EDGE, '', $p))
            ->map(fn ($p) => (string) preg_replace('/\b(pin(code)?|pin code|zip)\s*[:\-]?\s*$/i', '', $p))
            ->map(fn ($p) => (string) preg_replace(self::EDGE, '', $p))
            ->filter(fn ($p) => $p !== '')->values();

        // States of this country: full names and codes.
        [$names, $codes] = match ($code) {
            'IN' => [self::INDIAN_STATES, self::INDIAN_STATE_CODES],
            'US' => [array_values(self::US_STATES), self::US_STATES],
            'CA' => [array_values(self::CA_PROVINCES), self::CA_PROVINCES],
            'AU' => [array_values(self::AU_STATES), self::AU_STATES],
            default => [[], []],
        };
        $stateOf = function (string $p) use ($names, $codes): string {
            foreach ($names as $st) {
                if (strcasecmp($p, $st) === 0) {
                    return $st;
                }
            }
            $u = strtoupper(str_replace('.', '', $p));

            return $codes[$u] ?? '';
        };

        // Drop country and state parts (keeping what they name when not already known).
        $parts = $parts->reject(function ($p) use ($countryNames, $stateOf, &$out) {
            if (in_array(strtolower($p), $countryNames, true)) {
                return true;
            }
            if (($st = $stateOf($p)) !== '') {
                $out['state'] = $out['state'] ?: $st;

                return true;
            }

            return $out['state'] !== '' && strcasecmp($p, $out['state']) === 0;
        })->values();

        // A state written after the city in the same part: "Hyderabad TG", "Bengaluru Karnataka".
        if ($parts->isNotEmpty() && ($names || $codes)) {
            $lastPart = (string) $parts->last();
            foreach ([...array_keys($codes), ...$names] as $candidate) {
                if (preg_match('/^(.*\S)\s+'.preg_quote((string) $candidate, '/').'$/iu', $lastPart, $m)) {
                    $out['state'] = $out['state'] ?: $stateOf((string) $candidate);
                    $parts->pop();
                    $parts->push($m[1]);
                    break;
                }
            }
        }

        if ($india && $out['state'] === '' && $out['pincode'] !== '') {
            $out['state'] = self::indianStateFromPin($out['pincode']);
        }

        // City: the last remaining part, unless it reads like a street line.
        $cityLike = fn (string $p) => $p !== '' && ! preg_match('/\d/', $p) && ! preg_match(self::STREET_WORDS, $p)
            && ! preg_match('/^(county|country|dist(rict)?|taluk)\b/i', $p) && mb_strlen($p) <= 40;
        // A county line after the city ("Dover, County Kent, Delaware") stays in the street part.
        $county = null;
        if ($parts->count() > 1 && preg_match('/^(county|country)\b/i', (string) $parts->last())) {
            $county = (string) $parts->pop();
        }
        if ($parts->isNotEmpty()) {
            $candidate = preg_replace('/\s*(dist(rict)?\.?|urban|rural)$/i', '', (string) $parts->last());
            $candidate = preg_replace('/^(\p{L}[\p{L} ]*?)\s+\1$/iu', '$1', (string) $candidate);   // "Hyderabad Hyderabad"
            if ($cityLike((string) $candidate)) {
                $out['city'] = trim((string) $candidate);
                $parts->pop();
            } elseif ($india && ! preg_match('/^(county|country)\b/i', (string) $parts->last())) {
                // No comma before the city: "… Ramagondanahalli Bengaluru".
                foreach (self::INDIAN_CITIES as $city) {
                    if (preg_match('/^(.*\S)\s+('.preg_quote($city, '/').')$/iu', (string) $parts->last(), $m)) {
                        $out['city'] = $city;
                        $parts->pop();
                        $parts->push($m[1]);
                        break;
                    }
                }
            }
        }
        if ($county !== null) {
            $parts->push($county);
        }
        if ($out['city'] === '' && $code === 'SG' && $strippedCountry) {
            $out['city'] = 'Singapore';
        }

        // House: the first part when it looks like a door / building number (with the next part if it is only a number).
        if ($parts->count() > 1 && preg_match('/^(no\.?|#|flat|door|plot|h\.?\s?no\.?|\d)/i', (string) $parts->first())) {
            $house = (string) $parts->shift();
            // "207, EGPI Arcadia": a bare number takes the building name after it, not a road ("No 59, Temple Road").
            if (mb_strlen($house) <= 6 && $parts->count() > 1 && ! preg_match(self::STREET_WORDS, (string) $parts->first())) {
                $house .= ', '.$parts->shift();
            }
            $out['address_house'] = $house;
        }
        $out['address_street'] = $parts->implode(', ');

        $out['confidence'] = ($out['city'] !== ''
            && ($out['address_street'] !== '' || $out['address_house'] !== '')
            && $out['pincode'] !== ''
            && (! in_array($code, self::NEEDS_STATE, true) || $out['state'] !== ''))
            ? 'clean' : 'partial';

        return $out;
    }
}
