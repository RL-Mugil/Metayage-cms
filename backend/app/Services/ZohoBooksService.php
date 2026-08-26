<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only client for Zoho Books. No POST/PUT/DELETE methods exist here by
 * design — this integration only ever reads invoices/estimates/organizations.
 */
class ZohoBooksService
{
    private ?array $config = null;

    public function isConfigured(): bool
    {
        $c = $this->config();
        return ! empty($c['client_id']) && ! empty($c['client_secret'])
            && ! empty($c['refresh_token']) && ! empty($c['organization_id']);
    }

    private function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $raw = optional(Integration::where('slug', 'zoho')->first())->config ?? [];

        $this->config = [
            'client_id'       => $raw['client_id'] ?? null,
            'client_secret'   => isset($raw['client_secret']) ? decrypt($raw['client_secret']) : null,
            'refresh_token'   => isset($raw['refresh_token']) ? decrypt($raw['refresh_token']) : null,
            'organization_id' => $raw['organization_id'] ?? null,
            'region'          => $raw['region'] ?? 'in',
        ];

        return $this->config;
    }

    private function accountsDomain(): string
    {
        return 'https://accounts.zoho.' . $this->config()['region'];
    }

    private function apiDomain(): string
    {
        return 'https://www.zohoapis.' . $this->config()['region'];
    }

    public function getAccessToken(): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Zoho Books is not configured.');
        }

        return Cache::remember('zoho_access_token', 3300, function () {
            $c = $this->config();
            $response = Http::asForm()->post($this->accountsDomain() . '/oauth/v2/token', [
                'refresh_token' => $c['refresh_token'],
                'client_id'     => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'grant_type'    => 'refresh_token',
            ]);

            if (! $response->ok() || ! $response->json('access_token')) {
                throw new RuntimeException('Zoho token refresh failed: ' . $response->body());
            }

            return $response->json('access_token');
        });
    }

    private function get(string $path, array $query = []): array
    {
        $token = $this->getAccessToken();
        $query['organization_id'] = $this->config()['organization_id'];

        $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
            ->get($this->apiDomain() . '/books/v3' . $path, $query);

        if (! $response->successful()) {
            throw new RuntimeException('Zoho Books API error (' . $response->status() . '): ' . $response->body());
        }

        return $response->json() ?? [];
    }

    public function pingOrganization(): bool
    {
        $data = $this->get('/organizations/' . $this->config()['organization_id']);
        return (int) ($data['code'] ?? -1) === 0;
    }

    /** All invoices in the org, paginated to completion. Read-only, no per-customer filter. */
    public function listAllInvoices(): array
    {
        return $this->paginateAll('/invoices', 'invoices');
    }

    /** All estimates (quotes) in the org, paginated to completion. */
    public function listAllEstimates(): array
    {
        return $this->paginateAll('/estimates', 'estimates');
    }

    private function paginateAll(string $path, string $key): array
    {
        $all = [];
        $page = 1;

        do {
            $data = $this->get($path, ['page' => $page, 'per_page' => 200]);
            $all = array_merge($all, $data[$key] ?? []);
            $hasMore = (bool) ($data['page_context']['has_more_page'] ?? false);
            $page++;
        } while ($hasMore && $page <= 100); // safety cap: 20,000 records

        return $all;
    }
}
