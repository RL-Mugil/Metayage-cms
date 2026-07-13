<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientLedger;
use App\Models\AuditLog;
use App\Http\PaginationHelper;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;

class ClientController extends Controller
{
    // ── Inertia routes ────────────────────────────────────────────────────────
    public function inertiaIndex(Request $request)
    {
        return Inertia::render('Clients');
    }

    public function inertiaShow(Request $request, $id)
    {
        return Inertia::render('ClientShow', ['clientId' => (int) $id]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Generate next sequential client code: C00–C99, D00–D99, … Z99.
     * Must be called inside a DB::transaction() — uses lockForUpdate() to prevent
     * duplicate codes under concurrent requests.
     */
    private function generateClientCode(string $nationality): string
    {
        // Load all existing codes from DB once per import; filter in PHP.
        // No table-wide lock needed — we only need to find the max code; the
        // unique constraint on client_code catches true duplicates at insert.
        $clients = Client::whereNotNull('client_code')
            ->get(['client_code']);

        $last = $clients
            ->filter(fn($c) => preg_match('/^[C-Z][0-9]{2}[MY]?$/', $c->client_code))
            ->sort(function ($a, $b) {
                if (strlen($a->client_code) !== strlen($b->client_code)) {
                    return strlen($b->client_code) - strlen($a->client_code);
                }
                return strcmp($b->client_code, $a->client_code);
            })
            ->first()?->client_code;

        if (!$last) {
            $base = 'C00';
        } else {
            $letter = $last[0];
            $num = (int) substr($last, 1, 2);

            if ($num < 99) {
                $base = $letter . str_pad($num + 1, 2, '0', STR_PAD_LEFT);
            } else {
                $next = chr(ord($letter) + 1);
                $base = ($next > 'Z') ? 'C00' : $next . '00';
            }
        }

        $suffix = strtolower(trim($nationality)) === 'india' ? 'M' : 'Y';
        $candidate = $base . $suffix;

        while (Client::withTrashed()->where('client_code', $candidate)->exists()) {
            $letter = $base[0];
            $num = (int) substr($base, 1, 2);

            if ($num < 99) {
                $base = $letter . str_pad($num + 1, 2, '0', STR_PAD_LEFT);
            } else {
                $next = chr(ord($letter) + 1);
                $base = ($next > 'Z') ? 'C00' : $next . '00';
            }

            $candidate = $base . $suffix;
        }

        return $candidate;
    }

    /**
     * Derive Indian GST classification from client data.
     * B2B          → Indian + registered (has GSTIN)
     * B2C          → Indian + unregistered + individual
     * Unregistered → Indian + unregistered + organization
     * Export       → non-Indian nationality
     */
    private function computeGstType(string $nationality, bool $hasGstin, string $clientType): string
    {
        if (strtolower(trim($nationality)) !== 'india')
            return 'Export';
        if ($hasGstin)
            return 'B2B';
        if ($clientType === 'individual')
            return 'B2C';
        return 'Unregistered';
    }

    // ── API CRUD ──────────────────────────────────────────────────────────────

    public function stats(Request $request)
    {
        $user = $request->user();
        $base = Client::query();

        if ($user->isClientRole()) {
            $base->visibleToUser($user);
        } elseif ($user->isGalvanizer()) {
            $user->applyClientScope($base);
        }

        $cacheKey = "client_stats_{$user->id}_{$user->role}_" . implode('-', $user->galvanizerCircleCodes());
        $stats = Cache::remember($cacheKey, 300, function () use ($base) {
            $row = (clone $base)->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN status = 'Prospect' THEN 1 ELSE 0 END) as prospect,
                SUM(CASE WHEN gst_type = 'B2B' THEN 1 ELSE 0 END) as b2b,
                SUM(CASE WHEN gst_type = 'B2C' THEN 1 ELSE 0 END) as b2c,
                SUM(CASE WHEN gst_type = 'Export' THEN 1 ELSE 0 END) as export,
                SUM(CASE WHEN gst_type = 'Unregistered' THEN 1 ELSE 0 END) as unregistered
            ")->first();
            return [
                'total' => (int) ($row?->total ?? 0),
                'active' => (int) ($row?->active ?? 0),
                'inactive' => (int) ($row?->inactive ?? 0),
                'prospect' => (int) ($row?->prospect ?? 0),
                'b2b' => (int) ($row?->b2b ?? 0),
                'b2c' => (int) ($row?->b2c ?? 0),
                'export' => (int) ($row?->export ?? 0),
                'unregistered' => (int) ($row?->unregistered ?? 0),
            ];
        });

        return response()->json($stats);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Client::with('accountManager');

        if ($user->isClientRole()) {
            $query->visibleToUser($user);
        } elseif ($user->isGalvanizer()) {
            $user->applyClientScope($query);
        }

        if ($request->filled('search')) {
            $s = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->search);
            $query->where(function ($q) use ($s) {
                $q->where('company_name', 'ilike', "%{$s}%")
                    ->orWhere('client_code', 'ilike', "%{$s}%")
                    ->orWhere('legal_name', 'ilike', "%{$s}%")
                    ->orWhere('pan_number', 'ilike', "%{$s}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }

        if ($request->filled('account_manager_id')) {
            $query->where('account_manager_id', (int) $request->account_manager_id);
        }

        if ($request->filled('gst_type')) {
            $query->where('gst_type', $request->gst_type);
        }

        $sortBy = in_array($request->sort_by, ['company_name', 'legal_name', 'client_code', 'status', 'gst_type', 'date_onboarded'])
            ? $request->sort_by : 'company_name';
        $sortDir = $request->sort_dir === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortBy, $sortDir);

        return response()->json(PaginationHelper::paginate($query, $request));
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $client = Client::with('contacts', 'accountManager', 'projects')->findOrFail($id);

        if ($user->isClientRole() && ! $client->isVisibleToUser($user)) {
            return response()->json(['message' => 'Unauthorized Access'], 403);
        }
        $this->authorize('view', $client);

        return response()->json($client);
    }

    public function store(StoreClientRequest $request)
    {
        $user = $request->user();
        $v = $request->validated();

        $nationality = $v['nationality'] ?? 'India';
        $hasGstin = (bool) ($v['has_gstin'] ?? false);
        $clientType = $v['client_type'];

        $client = \DB::transaction(function () use ($v, $nationality, $hasGstin, $clientType, $user, $request) {
            if ($user->isGalvanizer()) {
                $v['circle'] = $v['circle'] ?? $user->defaultGalvanizerCircleCode();
                if (! $user->canAccessCircle($v['circle'] ?? null)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'circle' => 'Select one of your assigned circles.',
                    ]);
                }
            }

            $recordMode = $v['record_mode'] ?? 'new';
            $v['client_code'] = $recordMode === 'existing'
                ? strtoupper(trim((string) $v['client_code']))
                : $this->generateClientCode($nationality);
            $v['nationality'] = $nationality;
            $v['has_gstin'] = $hasGstin;
            $v['gst_type'] = $this->computeGstType($nationality, $hasGstin, $clientType);
            $v['company_name'] = $v['legal_name'];
            $v['account_manager_id'] = $v['account_manager_id'] ?? $user->id;
            $v['date_onboarded'] = now()->toDateString();
            $v['status'] = $v['status'] ?? 'Active';
            unset($v['record_mode']);

            $client = Client::create($v);

            // Seed a zero-balance opening entry so concurrent invoice/payment
            // creation always has a row to lockForUpdate() against.
            ClientLedger::create([
                'client_id' => $client->id,
                'transaction_date' => now()->toDateString(),
                'document_type' => 'Opening Balance',
                'document_reference' => $client->client_code,
                'debit' => 0,
                'credit' => 0,
                'balance' => 0,
                'notes' => 'Auto-seeded on client creation',
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'create',
                'subject_type' => 'Client',
                'subject_id' => $client->id,
                'metadata' => ['legal_name' => $client->legal_name, 'client_code' => $client->client_code],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $client;
        });

        return response()->json($client->load('accountManager'), 201);
    }

    public function update(UpdateClientRequest $request, $id)
    {
        $user = $request->user();
        $client = Client::findOrFail($id);
        $v = $request->validated();
        $this->authorize('update', $client);

        if ($user->isGalvanizer() && array_key_exists('circle', $v) && ! $user->canAccessCircle($v['circle'])) {
            return response()->json(['message' => 'You cannot move a client outside your assigned circle.'], 403);
        }

        $nationality = $v['nationality'] ?? $client->nationality ?? 'India';
        $hasGstin = array_key_exists('has_gstin', $v) ? (bool) $v['has_gstin'] : (bool) $client->has_gstin;
        $clientType = $v['client_type'] ?? $client->client_type ?? 'organization';

        $v['gst_type'] = $this->computeGstType($nationality, $hasGstin, $clientType);
        $v['nationality'] = $nationality;

        if (isset($v['legal_name'])) {
            $v['company_name'] = $v['legal_name'];
        }

        $client->update($v);

        // Sync circle to all projects belonging to this client
        if (array_key_exists('circle', $v)) {
            \App\Models\Project::where('client_id', $client->id)
                ->update(['circle' => $client->circle]);
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'update',
            'subject_type' => 'Client',
            'subject_id' => $client->id,
            'metadata' => array_keys($v),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json($client->fresh()->load('accountManager'));
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $client = Client::findOrFail($id);
        $this->authorize('delete', $client);

        // Prevent deletion if financial records exist (data integrity)
        $invoiceCount = \App\Models\Invoice::where('client_id', $id)->count();
        if ($invoiceCount > 0) {
            return response()->json([
                'message' => "Cannot delete client with {$invoiceCount} invoice(s). Use status 'Inactive' instead.",
            ], 422);
        }

        $name = $client->legal_name ?? $client->company_name;
        $client->contacts()->delete();
        $client->delete();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'delete',
            'subject_type' => 'Client',
            'subject_id' => $id,
            'metadata' => ['name' => $name],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Client deleted']);
    }

    // ── Import ────────────────────────────────────────────────────────────────

    public function import(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['super_admin', 'partner', 'manager', 'galvanizer'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'file'             => 'required_without:google_sheet_url|nullable|file|mimes:csv,xlsx,xls|max:5120',
            'google_sheet_url' => 'required_without:file|nullable|string',
            'skip_duplicates'  => 'nullable|string',
        ]);

        try {
            if ($request->hasFile('file')) {
                $rows = $this->parseUploadedFile($request->file('file'));
            } else {
                $rows = $this->parseGoogleSheet($request->input('google_sheet_url'));
            }
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // ── Phase 1: detect duplicates before user has decided ────────────────
        if (! $request->has('skip_duplicates')) {
            $duplicates = $this->detectClientDuplicates($rows);
            if (! empty($duplicates)) {
                return response()->json([
                    'requires_confirmation' => true,
                    'duplicates'            => $duplicates,
                ]);
            }
        }

        $skipDuplicates = filter_var($request->input('skip_duplicates', 'false'), FILTER_VALIDATE_BOOLEAN);
        $dupIndexes     = $request->has('skip_duplicates') ? $this->detectClientDuplicateIndexes($rows) : [];

        $imported = 0;
        $skipped = 0;
        $errors = [];

        \DB::transaction(function () use ($rows, $user, $skipDuplicates, $dupIndexes, &$imported, &$skipped, &$errors) {
            foreach ($rows as $index => $row) {
                $legalName = trim((string) ($row['legal_name'] ?? $row['company_name'] ?? $row['full_name'] ?? ''));
                if (!$legalName) {
                    $skipped++;
                    continue;
                }

                // Handle duplicate rows per user decision
                if (isset($dupIndexes[$index])) {
                    if ($skipDuplicates) { $skipped++; continue; }
                    // Import anyway — allow the duplicate client to be created
                }

                try {
                    $nationality = trim((string) ($row['nationality'] ?? 'India')) ?: 'India';
                    $hasGstin = filter_var($row['has_gstin'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    $clientType = in_array($row['client_type'] ?? '', ['individual', 'organization'])
                        ? (string) $row['client_type'] : 'organization';

                    $email = isset($row['contact_email']) && filter_var($row['contact_email'], FILTER_VALIDATE_EMAIL)
                        ? (string) $row['contact_email'] : null;

                    $validStatuses = ['Active', 'Inactive', 'Prospect', 'On Hold'];
                    $status = in_array($row['status'] ?? '', $validStatuses) ? (string) $row['status'] : 'Active';

                    Client::create([
                        'client_code' => $this->generateClientCode($nationality),
                        'legal_name' => $legalName,
                        'company_name' => $legalName,
                        'client_type' => $clientType,
                        'nationality' => $nationality,
                        'has_gstin' => $hasGstin,
                        'gst_type' => $this->computeGstType($nationality, $hasGstin, $clientType),
                        'pan_number' => isset($row['pan_number']) ? strtoupper(trim((string) $row['pan_number'])) : null,
                        'cin_number' => isset($row['cin_number']) ? strtoupper(trim((string) $row['cin_number'])) : null,
                        'entity_subtype' => $row['entity_subtype'] ?? null,
                        'trade_name' => $row['trade_name'] ?? null,
                        'website' => $row['website'] ?? null,
                        'contact_name' => $row['contact_name'] ?? null,
                        'contact_email' => $email,
                        'phone' => $row['phone'] ?? null,
                        'address' => $row['address'] ?? null,
                        'state' => $row['state'] ?? null,
                        'industry' => $row['industry'] ?? null,
                        'payment_terms' => $row['payment_terms'] ?? 'Net 30',
                        'account_manager_id' => $user->id,
                        'date_onboarded' => now()->toDateString(),
                        'status' => $status,
                        'remarks' => $row['remarks'] ?? null,
                    ]);
                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = 'Row ' . ($index + 2) . ': ' . $e->getMessage();
                    $skipped++;
                }
            }
        });

        return response()->json([
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }

    private function detectClientDuplicates(array $rows): array
    {
        $duplicates = [];
        $seenInFile = [];
        foreach ($rows as $i => $row) {
            $name = mb_strtolower(trim((string) ($row['legal_name'] ?? $row['company_name'] ?? $row['full_name'] ?? '')));
            if ($name === '') continue;
            $line = $i + 2;
            if (isset($seenInFile[$name])) {
                $duplicates[] = ['line' => $line, 'name' => trim((string) ($row['legal_name'] ?? $row['company_name'] ?? $row['full_name'] ?? '')), 'reason' => "same as row {$seenInFile[$name]} in this file"];
                continue;
            }
            $seenInFile[$name] = $line;
            $exists = \App\Models\Client::whereRaw('LOWER(legal_name) = ?', [$name])
                ->orWhereRaw('LOWER(company_name) = ?', [$name])
                ->exists();
            if ($exists) {
                $duplicates[] = ['line' => $line, 'name' => trim((string) ($row['legal_name'] ?? $row['company_name'] ?? $row['full_name'] ?? '')), 'reason' => 'already exists in system'];
            }
        }
        return $duplicates;
    }

    private function detectClientDuplicateIndexes(array $rows): array
    {
        $indexes    = [];
        $seenInFile = [];
        foreach ($rows as $i => $row) {
            $name = mb_strtolower(trim((string) ($row['legal_name'] ?? $row['company_name'] ?? $row['full_name'] ?? '')));
            if ($name === '') continue;
            if (isset($seenInFile[$name])) { $indexes[$i] = true; continue; }
            $seenInFile[$name] = $i;
            $exists = \App\Models\Client::whereRaw('LOWER(legal_name) = ?', [$name])
                ->orWhereRaw('LOWER(company_name) = ?', [$name])
                ->exists();
            if ($exists) $indexes[$i] = true;
        }
        return $indexes;
    }

    private function parseUploadedFile(\Illuminate\Http\UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, ['xlsx', 'xls'])) {
            return $this->parseXlsx($file->getRealPath());
        }
        return $this->parseCsvFile($file->getRealPath());
    }

    private function parseCsvFile(string $path): array
    {
        $rows = [];
        $headers = null;

        if (($handle = fopen($path, 'r')) !== false) {
            while (($line = fgetcsv($handle)) !== false) {
                if ($headers === null) {
                    $headers = array_map(
                        fn($h) => strtolower(str_replace([' ', '-'], '_', trim((string) ($h ?? '')))),
                        $line
                    );
                    continue;
                }
                if (count($line) >= count($headers)) {
                    $rows[] = array_combine($headers, array_slice($line, 0, count($headers)));
                }
            }
            fclose($handle);
        }

        return $rows;
    }

    private function parseXlsx(string $path): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rawRows = $sheet->toArray(null, true, true, false);

        if (empty($rawRows))
            return [];

        $headers = array_map(
            fn($h) => strtolower(str_replace([' ', '-'], '_', trim((string) ($h ?? '')))),
            $rawRows[0]
        );
        $headers = array_filter($headers, fn($h) => $h !== '');

        $result = [];
        for ($i = 1; $i < count($rawRows); $i++) {
            $row = array_slice($rawRows[$i], 0, count($headers));
            while (count($row) < count($headers)) {
                $row[] = null;
            }
            $result[] = array_combine($headers, $row);
        }

        return $result;
    }

    private function parseGoogleSheet(string $url): array
    {
        if (!preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $url, $m)) {
            throw new \Exception('Invalid Google Sheet URL. Expected: https://docs.google.com/spreadsheets/d/{ID}/...');
        }

        $sheetId = $m[1];
        $gid = '0';
        if (preg_match('/[?&]gid=(\d+)/', $url, $gm)) {
            $gid = $gm[1];
        }

        $csvUrl = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$gid}";
        $response = Http::timeout(15)->get($csvUrl);

        if (!$response->ok()) {
            throw new \Exception('Could not fetch Google Sheet. Make sure it is set to "Anyone with link can view".');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'gsheet_');
        file_put_contents($tmpFile, $response->body());
        $rows = $this->parseCsvFile($tmpFile);
        @unlink($tmpFile);

        return $rows;
    }

    // ── Contacts ──────────────────────────────────────────────────────────────

    public function addContact(Request $request, $id)
    {
        $user = $request->user();
        if (!in_array($user->role, ['super_admin', 'partner', 'manager', 'galvanizer'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $client = Client::findOrFail($id);
        $this->authorize('update', $client);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'title' => 'nullable|string|max:255',
            'email' => 'required|email|unique:client_contacts,email',
            'phone' => 'nullable|string',
            'role_type' => 'nullable|string',
        ]);

        $validated['client_id'] = $client->id;
        return response()->json(ClientContact::create($validated), 201);
    }
}
