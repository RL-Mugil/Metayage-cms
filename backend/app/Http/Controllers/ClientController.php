<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\AuditLog;
use App\Http\PaginationHelper;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use Illuminate\Http\Request;
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
                $q->where('company_name', 'ilike', "%{$s}%")
                  ->orWhere('client_code', 'ilike', "%{$s}%")
                  ->orWhere('legal_name',  'ilike', "%{$s}%")
                  ->orWhere('pan_number',  'ilike', "%{$s}%");
            });
        }

        $query->orderBy('company_name');
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
