<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Company letterhead for generated documents: the logo (top centre) and the footer lines
 * (company name, address, phone and web). Defaults are the firm's letterhead; each line can be
 * overridden in system_settings (letterhead_company_name, letterhead_address, letterhead_phone,
 * letterhead_web).
 */
class Letterhead
{
    public const LOGO = 'letterhead/metayage-logo.png';

    private const DEFAULTS = [
        'company_name' => 'Metayage Pvt. Ltd.',
        'address' => '207 EGPI Arcadia, Jai Bharath Nagar, Banaswadi Main Road, Cooke Town, Bengaluru, Karnataka 560033, India',
        'phone' => '(+91)-9886572283',
        'web' => 'www.metayage.com',
    ];

    /** @return array{company_name: string, address: string, phone: string, web: string, logo_path: string, logo_data_uri: string} */
    public function get(): array
    {
        $saved = DB::table('system_settings')->whereIn('key', ['letterhead_company_name', 'letterhead_address', 'letterhead_phone', 'letterhead_web'])
            ->pluck('value', 'key');
        $path = resource_path(self::LOGO);

        return [
            'company_name' => $saved['letterhead_company_name'] ?? self::DEFAULTS['company_name'],
            'address' => $saved['letterhead_address'] ?? self::DEFAULTS['address'],
            'phone' => $saved['letterhead_phone'] ?? self::DEFAULTS['phone'],
            'web' => $saved['letterhead_web'] ?? self::DEFAULTS['web'],
            'logo_path' => $path,
            'logo_data_uri' => is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : '',
        ];
    }
}
