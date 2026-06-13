<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\AuditLog;
use App\Http\PaginationHelper;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
     * Ignores legacy CLI-XXXX format codes.
     */
    private function generateClientCode(string $nationality): string
    {
        // Match both old (C00) and new (C00M / C00Y) formats when looking up last code
        $clients = Client::whereNotNull('client_code')
            ->orderByRaw("LENGTH(client_code) DESC, client_code DESC")
            ->get(['client_code']);

        $last = null;
        foreach ($clients as $client) {
            $code = $client->client_code;
            if (preg_match('/^[C-Z]\d{2}[MY]?$/', $code)) {
                $last = $code;
                break;
            }
        }

        if (!$last) {
            $base = 'C00';
        } else {
            $letter = $last[0];
            $num    = (int) substr($last, 1, 2);

            if ($num < 99) {
                $base = $letter . str_pad($num + 1, 2, '0', STR_PAD_LEFT);
            } else {
                $next = chr(ord($letter) + 1);
                $base = ($next > 'Z') ? 'C00' : $next . '00';
            }
        }

        $suffix = strtolower(trim($nationality)) === 'india' ? 'M' : 'Y';
        return $base . $suffix;
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
        if (strtolower(trim($nationality)) !== 'india') return 'Export';
        if ($hasGstin) return 'B2B';
        if ($clientType === 'individual') return 'B2C';
        return 'Unregistered';
    }

    // ── API CRUD ──────────────────────────────────────────────────────────────

    public function stats(Request $request)
    {
        $user = $request->user();
        $base = Client::query();

        if ($user->role === 'client') {
            $base->whereHas('contacts', fn($q) => $q->where('email', $user->email));
        }

        return response()->json([
            'total'        => (clone $base)->count(),
            'active'       => (clone $base)->where('status', 'Active')->count(),
            'inactive'     => (clone $base)->where('status', 'Inactive')->count(),
            'prospect'     => (clone $base)->where('status', 'Prospect')->count(),
            'b2b'          => (clone $base)->where('gst_type', 'B2B')->count(),
            'b2c'          => (clone $base)->where('gst_type', 'B2C')->count(),
            'export'       => (clone $base)->where('gst_type', 'Export')->count(),
            'unregistered' => (clone $base)->where('gst_type', 'Unregistered')->count(),
        ]);
    }

    public function index(Request $request)
    {
        $user  = $request->user();
        $query = Client::with('accountManager');

        if ($user->role === 'client') {
            $query->whereHas('contacts', fn($q) => $q->where('email', $user->email));
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('company_name', 'like', "%{$s}%")
                  ->orWhere('client_code', 'like', "%{$s}%")
                  ->orWhere('legal_name',  'like', "%{$s}%")
                  ->orWhere('pan_number',  'like', "%{$s}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }

        if ($request->filled('gst_type')) {
            $query->where('gst_type', $request->gst_type);
        }

        $sortBy  = in_array($request->sort_by, ['company_name', 'legal_name', 'client_code', 'status', 'gst_type', 'date_onboarded'])
            ? $request->sort_by : 'company_name';
        $sortDir = $request->sort_dir === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortBy, $sortDir);

        return response()->json(PaginationHelper::paginate($query, $request));
    }

    public function show(Request $request, $id)
    {
        $user   = $request->user();
        $client = Client::with('contacts', 'accountManager', 'projects')->findOrFail($id);

        if ($user->role === 'client' && !$client->contacts()->where('email', $user->email)->exists()) {
            return response()->json(['message' => 'Unauthorized Access'], 403);
        }

        return response()->json($client);
    }

    public function store(StoreClientRequest $request)
    {
        $user = $request->user();

        $v = $request->validated();

        $nationality = $v['nationality']  ?? 'India';
        $hasGstin    = (bool) ($v['has_gstin'] ?? false);
        $clientType  = $v['client_type'];

        $v['client_code']        = $this->generateClientCode($nationality);
        $v['nationality']        = $nationality;
        $v['has_gstin']          = $hasGstin;
        $v['gst_type']           = $this->computeGstType($nationality, $hasGstin, $clientType);
        $v['company_name']       = $v['legal_name'];
        $v['account_manager_id'] = $v['account_manager_id'] ?? $user->id;
        $v['date_onboarded']     = now()->toDateString();
        $v['status']             = $v['status'] ?? 'Active';

        $client = Client::create($v);

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'create',
            'subject_type' => 'Client',
            'subject_id'   => $client->id,
            'metadata'     => ['legal_name' => $client->legal_name, 'client_code' => $client->client_code],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json($client->load('accountManager'), 201);
    }

    public function update(UpdateClientRequest $request, $id)
    {
        $user = $request->user();
        $client = Client::findOrFail($id);
        $v = $request->validated();

        $nationality = $v['nationality'] ?? $client->nationality ?? 'India';
        $hasGstin    = array_key_exists('has_gstin', $v) ? (bool) $v['has_gstin'] : (bool) $client->has_gstin;
        $clientType  = $v['client_type'] ?? $client->client_type ?? 'organization';

        $v['gst_type']    = $this->computeGstType($nationality, $hasGstin, $clientType);
        $v['nationality'] = $nationality;

        if (isset($v['legal_name'])) {
            $v['company_name'] = $v['legal_name'];
        }

        $client->update($v);

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'update',
            'subject_type' => 'Client',
            'subject_id'   => $client->id,
            'metadata'     => array_keys($v),
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json($client->fresh()->load('accountManager'));
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!in_array($user->role, ['super_admin', 'partner'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $client = Client::findOrFail($id);

        // Prevent deletion if financial records exist (data integrity)
        $invoiceCount = \App\Models\Invoice::where('client_id', $id)->count();
        if ($invoiceCount > 0) {
            return response()->json([
                'message' => "Cannot delete client with {$invoiceCount} invoice(s). Use status 'Inactive' instead.",
            ], 422);
        }

        $name = $client->legal_name ?? $client->company_name;
        $client->delete();

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'delete',
            'subject_type' => 'Client',
            'subject_id'   => $id,
            'metadata'     => ['name' => $name],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Client deleted']);
    }

    // ── Import ────────────────────────────────────────────────────────────────

    public function import(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['super_admin', 'partner', 'manager'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'file'             => 'required_without:google_sheet_url|nullable|file|mimes:csv,xlsx,xls|max:5120',
            'google_sheet_url' => 'required_without:file|nullable|string',
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

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($rows as $index => $row) {
            $legalName = trim((string) ($row['legal_name'] ?? $row['company_name'] ?? $row['full_name'] ?? ''));
            if (!$legalName) {
                $skipped++;
                continue;
            }

            try {
                $nationality = trim((string) ($row['nationality'] ?? 'India')) ?: 'India';
                $hasGstin    = filter_var($row['has_gstin'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $clientType  = in_array($row['client_type'] ?? '', ['individual', 'organization'])
                               ? (string) $row['client_type'] : 'organization';

                $email = isset($row['contact_email']) && filter_var($row['contact_email'], FILTER_VALIDATE_EMAIL)
                         ? (string) $row['contact_email'] : null;

                $validStatuses = ['Active', 'Inactive', 'Prospect', 'On Hold'];
                $status = in_array($row['status'] ?? '', $validStatuses) ? (string) $row['status'] : 'Active';

                Client::create([
                    'client_code'    => $this->generateClientCode($nationality),
                    'legal_name'     => $legalName,
                    'company_name'   => $legalName,
                    'client_type'    => $clientType,
                    'nationality'    => $nationality,
                    'has_gstin'      => $hasGstin,
                    'gst_type'       => $this->computeGstType($nationality, $hasGstin, $clientType),
                    'pan_number'     => isset($row['pan_number'])     ? strtoupper(trim((string) $row['pan_number'])) : null,
                    'cin_number'     => isset($row['cin_number'])     ? strtoupper(trim((string) $row['cin_number'])) : null,
                    'entity_subtype' => $row['entity_subtype'] ?? null,
                    'trade_name'     => $row['trade_name']    ?? null,
                    'website'        => $row['website']       ?? null,
                    'contact_name'   => $row['contact_name']  ?? null,
                    'contact_email'  => $email,
                    'phone'          => $row['phone']         ?? null,
                    'address'        => $row['address']       ?? null,
                    'state'          => $row['state']         ?? null,
                    'industry'       => $row['industry']      ?? null,
                    'payment_terms'  => $row['payment_terms'] ?? 'Net 30',
                    'account_manager_id' => $user->id,
                    'date_onboarded' => now()->toDateString(),
                    'status'         => $status,
                    'remarks'        => $row['remarks']       ?? null,
                ]);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = 'Row ' . ($index + 2) . ': ' . $e->getMessage();
                $skipped++;
            }
        }

        return response()->json([
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ]);
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
        $rows    = [];
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
        $sheet       = $spreadsheet->getActiveSheet();
        $rawRows     = $sheet->toArray(null, true, true, false);

        if (empty($rawRows)) return [];

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
        $gid     = '0';
        if (preg_match('/[?&]gid=(\d+)/', $url, $gm)) {
            $gid = $gm[1];
        }

        $csvUrl   = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$gid}";
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
        if (!in_array($user->role, ['super_admin', 'partner', 'manager'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $client    = Client::findOrFail($id);
        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'title'     => 'nullable|string|max:255',
            'email'     => 'required|email|unique:client_contacts,email',
            'phone'     => 'nullable|string',
            'role_type' => 'nullable|string',
        ]);

        $validated['client_id'] = $client->id;
        return response()->json(ClientContact::create($validated), 201);
    }
}
