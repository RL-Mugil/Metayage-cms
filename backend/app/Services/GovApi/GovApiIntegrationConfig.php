<?php

namespace App\Services\GovApi;

use App\Models\Integration;

/**
 * Shared credential-loading helper for the three API Setu-marketplace
 * integrations (PAN Verification, DigiLocker, EntityLocker) — reads from the
 * same `integrations` table/config column already used for Zoho Books (see
 * IntegrationController), pasted in by an admin via Settings -> Integrations
 * once the corresponding API Setu consumer request is approved.
 */
class GovApiIntegrationConfig
{
    /**
     * Deliberately uncached (no static/instance memoization) — a single
     * lightweight row lookup, and caching risks serving stale credentials
     * within the same request/process if config was just saved (e.g. under
     * a persistent-worker runtime like Octane, unlike stateless PHP-FPM).
     */
    public static function get(string $slug): array
    {
        $raw = optional(Integration::where('slug', $slug)->first())->config ?? [];

        $decrypted = $raw;
        if (isset($raw['client_secret'])) {
            try {
                $decrypted['client_secret'] = decrypt($raw['client_secret']);
            } catch (\Throwable) {
                $decrypted['client_secret'] = null;
            }
        }

        return $decrypted;
    }
}
