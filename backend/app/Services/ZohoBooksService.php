<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Illuminate\Http\Client\Response;

/**
 * Reads Books records, creates draft estimates, and converts an existing estimate
 * into one invoice. Sending/emailing remains explicitly outside this service.
 */
class ZohoBooksService
{
    private ?array $config = null;
    public bool $background = false;
    public ?string $eveningDate = null;
    public bool $emergency = false;
    public bool $addon = false;
    public bool $polling = false;

    public function scope(): string
    {
        return $this->config()['region'] . ':' . $this->config()['organization_id'];
    }

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

        return Cache::remember('zoho_access_token:' . $this->scope(), 3300, function () {
            $c = $this->config();
            $response = Http::connectTimeout(10)->timeout(30)->withOptions(['allow_redirects' => false])
                ->asForm()->post($this->accountsDomain() . '/oauth/v2/token', [
                'refresh_token' => $c['refresh_token'],
                'client_id'     => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'grant_type'    => 'refresh_token',
            ]);

            if (! $response->ok() || ! $response->json('access_token')) {
                throw new RuntimeException('Zoho token refresh failed. Check the integration credentials and OAuth scopes.');
            }

            return $response->json('access_token');
        });
    }

    private function get(string $path, array $query = []): array
    {
        $response = $this->request($path, $query);
        $data = $response->json();
        if (! is_array($data) || (int) ($data['code'] ?? 0) !== 0) {
            throw new RuntimeException('Zoho returned an invalid or unsuccessful response.');
        }
        return $data;
    }

    private function request(string $path, array $query = [], ?array $draft = null, string $method = 'GET'): Response
    {
        if (! $this->background) {
            Cache::put('zoho_manual_waiting:' . $this->scope(), true, 5);
        } elseif ($this->eveningDate !== null && Cache::has('zoho_manual_waiting:' . $this->scope())) {
            throw new \App\Exceptions\ZohoBudgetExhausted('Evening continuation yielded to a manual request.', 5, 'manual_priority');
        }
        // Include connection tests and token renewal in the same transport lock.
        // One active Books request per organization stays below Zoho concurrency limits.
        $lock = Cache::lock('zoho_request:' . $this->scope(), 90);
        if (! $lock->get()) {
            throw new \App\Exceptions\ZohoBudgetExhausted('Another Zoho request is in progress. Try shortly.', 1);
        }
        try {
            return $this->send($path, $query, $draft, $method);
        } finally {
            $lock->release();
        }
    }

    private function send(string $path, array $query, ?array $draft, string $method = 'GET'): Response
    {
        // Reserve before authentication so an exhausted budget makes no network calls.
        // OAuth is a separate Accounts request; each Books operation reserves one call.
        $write = $draft !== null || in_array($method, ['DELETE', 'POST'], true);
        app(ZohoApiBudget::class)->reserve($this->scope(), $this->background, $write, $this->eveningDate, $this->emergency, $this->addon);
        try {
            $token = $this->getAccessToken();
        } catch (\Throwable $error) {
            if ($write) {
                throw new \App\Exceptions\ZohoWriteNotSent('Authentication failed before the draft was sent.');
            }
            throw $error;
        }
        $query['organization_id'] = $this->config()['organization_id'];
        if ($this->eveningDate !== null) {
            app(ZohoApiBudget::class)->assertEvening($this->scope(), $this->eveningDate);
        }
        $http = Http::connectTimeout(10)->timeout(30)->withOptions(['allow_redirects' => false])
            ->withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token]);
        $response = $draft !== null
            ? $http->withQueryParameters($query)->post($this->apiDomain() . '/books/v3' . $path, $draft)
            : ($method === 'DELETE'
                ? $http->delete($this->apiDomain() . '/books/v3' . $path, $query)
                : ($method === 'POST'
                    ? $http->withQueryParameters($query)->post($this->apiDomain() . '/books/v3' . $path)
                    : $http->get($this->apiDomain() . '/books/v3' . $path, $query)));

        $code = (int) ($response->json('code') ?? 0);
        // A generic 404 (or a PDF 404) is not proof that a financial record was deleted.
        if ($draft === null && ! isset($query['accept']) && in_array($response->status(), [400, 404], true)
            && $code === 1002 && preg_match('~^/(invoices|estimates)/[^/]+$~', $path, $match)) {
            $entity = $match[1] === 'invoices' ? 'Invoice' : 'Estimate';
            $missingMessages = [$entity . ' does not exist', 'Resource does not exist'];
            if ($match[1] === 'estimates') {
                $missingMessages[] = 'Quote does not exist';
            }
            // Books also returns the generic entity label "Resource" for a
            // removed ID. Require code 1002 on a supported detail URL, never PDFs.
            if (in_array(rtrim(trim((string) $response->json('message')), '.'), $missingMessages, true)) {
                throw new \App\Exceptions\ZohoRecordMissing('Zoho confirmed that the requested record no longer exists.');
            }
        }
        if ($response->status() === 429 || in_array($code, [44, 45, 1070], true)) {
            $seconds = $code === 45
                ? (int) ceil(now()->diffInSeconds(now('Asia/Kolkata')->addDay()->startOfDay())) : 60;
            $retry = $response->header('Retry-After');
            if (is_numeric($retry)) {
                $seconds = max(1, (int) ceil((float) $retry));
            } elseif ($retry) {
                try {
                    $seconds = max(1, (int) ceil(now()->diffInSeconds(\Carbon\Carbon::parse($retry))));
                } catch (\Throwable) {
                    // An invalid header falls back to the documented limit category.
                }
            }
            app(ZohoApiBudget::class)->coolDown($this->scope(), $seconds);
            throw new \App\Exceptions\ZohoBudgetExhausted('Zoho rate limit reached. Sync paused with progress saved.', $seconds);
        }

        if (! $response->successful()) {
            if ($response->status() === 401) {
                Cache::forget('zoho_access_token:' . $this->scope());
            }
            throw new \App\Exceptions\ZohoApiException($response->status());
        }

        return $response;
    }

    /** Exactly one Books POST; never send an email or create an invoice. */
    public function createDraftEstimate(array $payload): array
    {
        $response = $this->request('/estimates', ['send' => 'false'], $payload);
        $data = $response->json();
        if (! is_array($data) || (int) ($data['code'] ?? -1) !== 0
            || empty($data['estimate']['estimate_id']) || ($data['estimate']['status'] ?? null) !== 'draft') {
            throw new RuntimeException('The draft creation outcome could not be confirmed. Reconcile before sending again.');
        }
        return $data['estimate'];
    }

    /** Exactly one Books DELETE for an explicitly confirmed manual quote action. */
    public function deleteEstimate(string $estimateId): void
    {
        abort_unless(preg_match('/^[0-9]{1,80}$/', $estimateId) === 1, 422, 'Invalid Zoho estimate ID.');
        $response = $this->request('/estimates/' . rawurlencode($estimateId), [], null, 'DELETE');
        $data = $response->json();
        if (! is_array($data) || (int) ($data['code'] ?? -1) !== 0) {
            throw new RuntimeException('Zoho did not confirm deletion of the quotation.');
        }
    }

    /** Convert an existing estimate; this never creates a second independent invoice. */
    public function createInvoiceFromEstimate(string $estimateId): array
    {
        abort_unless(preg_match('/^[0-9]{1,80}$/', $estimateId) === 1, 422, 'Invalid Zoho estimate ID.');
        $response = $this->request('/invoices/fromestimates', [], ['estimate_ids' => [$estimateId]], 'POST');
        $data = $response->json();
        $invoice = $data['invoices'][0] ?? $data['invoice'] ?? null;
        if (! is_array($invoice) || empty($invoice['invoice_id'])) {
            throw new RuntimeException('Zoho did not confirm invoice conversion from the estimate.');
        }
        return $invoice;
    }

    public const RESOURCES = [
        'invoices' => ['invoice_id', 'invoice'],
        'estimates' => ['estimate_id', 'estimate'],
        'customerpayments' => ['payment_id', 'payment'],
        'creditnotes' => ['creditnote_id', 'creditnote'],
        'contacts' => ['contact_id', 'contact'],
    ];

    public function page(string $resource, int $page): array
    {
        return $this->listPage($resource, ['page' => $page, 'per_page' => 200]);
    }

    public function listPage(string $resource, array $query = []): array
    {
        $this->validateResource($resource);
        $data = $this->get('/' . $resource, $query + ['per_page' => 200]);
        if (! isset($data[$resource]) || ! is_array($data[$resource])
            || ! array_is_list($data[$resource])
            || ! is_bool($data['page_context']['has_more_page'] ?? null)) {
            throw new RuntimeException('Incomplete Zoho list response; progress was not advanced.');
        }
        return $data;
    }

    public function detail(string $resource, string $id): array
    {
        $this->validateResource($resource);
        $data = $this->get('/' . $resource . '/' . rawurlencode($id));
        $record = $data[self::RESOURCES[$resource][1]] ?? null;
        if (! is_array($record) || (string) ($record[self::RESOURCES[$resource][0]] ?? '') !== $id) {
            throw new RuntimeException('Zoho detail response did not identify the requested record.');
        }
        return $record;
    }

    public function pdf(string $resource, string $id): string
    {
        if (! in_array($resource, ['invoices', 'estimates', 'creditnotes'], true)) {
            throw new RuntimeException('Unsupported PDF resource.');
        }
        $body = $this->request('/' . $resource . '/' . rawurlencode($id), ['accept' => 'pdf'])->body();
        if (! str_starts_with($body, '%PDF-')) {
            throw new RuntimeException('Zoho did not return a valid PDF.');
        }
        return $body;
    }

    private function validateResource(string $resource): void
    {
        if (! isset(self::RESOURCES[$resource])) {
            throw new RuntimeException('Unsupported Zoho resource.');
        }
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
            $data = $this->page(ltrim($path, '/'), $page);
            $all = array_merge($all, $data[$key] ?? []);
            $hasMore = (bool) ($data['page_context']['has_more_page'] ?? false);
            $page++;
        } while ($hasMore);

        return $all;
    }
}
