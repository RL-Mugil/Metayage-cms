<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\ZohoExportImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Financial Suite → "Import Zoho export": upload Zoho Books' Invoice / Quote / Customer
 * Payment CSV exports, preview what is new / changed / identical (nothing written), then
 * import. Super admin, partner and finance only.
 */
class ZohoExportController extends Controller
{
    private const ROLES = ['super_admin', 'partner', 'finance'];
    private const DISK = 'local';
    private const ORDER = ['invoices' => 0, 'quotes' => 1, 'payments' => 2];

    public function __construct(private readonly ZohoExportImporter $importer) {}

    public function preview(Request $request): JsonResponse
    {
        $this->allow($request);
        $request->validate([
            'files'   => 'required|array|min:1|max:3',
            'files.*' => 'required|file|max:20480|mimes:csv,txt',
        ]);

        $token = (string) Str::uuid();
        $stored = [];
        foreach ($request->file('files') as $file) {
            $path = $file->storeAs("zoho-exports/{$token}", Str::random(8).'.csv', self::DISK);
            $absolute = Storage::disk(self::DISK)->path($path);
            try {
                $kind = $this->importer->detect($absolute);
            } catch (\RuntimeException $e) {
                Storage::disk(self::DISK)->deleteDirectory("zoho-exports/{$token}");
                throw ValidationException::withMessages(['files' => $file->getClientOriginalName().': '.$e->getMessage()]);
            }
            $stored[$kind] = ['path' => $path, 'name' => $file->getClientOriginalName()];
        }
        // Invoices before payments, so a payment can find the invoice it was applied to.
        uksort($stored, fn ($a, $b) => self::ORDER[$a] <=> self::ORDER[$b]);

        $reports = [];
        foreach ($stored as $kind => $file) {
            $reports[] = ['file' => $file['name']] + $this->importer->run(Storage::disk(self::DISK)->path($file['path']), false);
        }
        Cache::put("zoho-export:{$token}", ['user' => $request->user()->id, 'files' => $stored], now()->addMinutes(30));

        return response()->json(['token' => $token, 'reports' => $reports, 'reconciliation' => $this->importer->reconcile($reports)]);
    }

    public function import(Request $request): JsonResponse
    {
        $this->allow($request);
        $validated = $request->validate(['token' => 'required|uuid']);
        $batch = Cache::pull("zoho-export:{$validated['token']}");
        if (! $batch || (int) $batch['user'] !== (int) $request->user()->id) {
            throw ValidationException::withMessages(['token' => 'This preview has expired. Upload the files again.']);
        }

        $reports = [];
        foreach ($batch['files'] as $kind => $file) {
            $reports[] = ['file' => $file['name']] + $this->importer->run(Storage::disk(self::DISK)->path($file['path']), true);
        }
        Storage::disk(self::DISK)->deleteDirectory("zoho-exports/{$validated['token']}");
        \Illuminate\Support\Facades\Cache::increment('dashboard_v');

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'import_zoho_export', 'subject_type' => 'Finance', 'subject_id' => 0,
            'metadata' => collect($reports)->map(fn ($r) => array_intersect_key($r, array_flip(['file', 'kind', 'documents', 'new', 'changed', 'identical'])))->all(),
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        // Export totals beside the portal's after the import: the difference should be 0.
        return response()->json(['reports' => $reports, 'reconciliation' => $this->importer->reconcile($reports)]);
    }

    private function allow(Request $request): void
    {
        abort_unless(in_array($request->user()->role, self::ROLES, true), 403, 'Only super admin, partner or finance can import Zoho exports.');
    }
}
