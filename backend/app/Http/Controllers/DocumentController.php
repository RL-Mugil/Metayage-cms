<?php
namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    // Document storage is firm-wide and unscoped, so client portal users
    // must not reach it until per-client scoping exists.
    private const INTERNAL_ROLES = ['super_admin', 'partner', 'manager', 'associate', 'paralegal', 'finance', 'hr'];

    private function denyNonInternal(Request $request)
    {
        if (! in_array($request->user()->role, self::INTERNAL_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    public const FOLDERS = ['General', 'Patents', 'Trademarks', 'Contracts', 'Correspondence', 'Invoices'];

    public function index(Request $request)
    {
        if ($deny = $this->denyNonInternal($request)) return $deny;

        $perPage = max(1, min(500, (int) $request->query('per_page', 50)));
        $page    = max(1, (int) $request->query('page', 1));

        $folder = $request->query('folder');

        $all = collect(Storage::disk('local')->allFiles('documents'))
            ->map(function ($path) {
                $parts  = explode('/', $path);
                $folder = count($parts) > 2 ? $parts[1] : 'General';
                return [
                    'name'     => basename($path),
                    'path'     => $path,
                    'folder'   => in_array($folder, self::FOLDERS) ? $folder : 'General',
                    'size'     => Storage::disk('local')->size($path),
                    'modified' => Storage::disk('local')->lastModified($path),
                ];
            })
            ->sortByDesc('modified')
            ->values();

        if ($folder && in_array($folder, self::FOLDERS)) {
            $all = $all->where('folder', $folder)->values();
        }

        $total = $all->count();
        $data  = $all->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data'         => $data,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => max(1, (int) ceil($total / $perPage)),
            'has_more'     => ($page * $perPage) < $total,
        ]);
    }

    public function store(Request $request)
    {
        if ($deny = $this->denyNonInternal($request)) return $deny;

        $request->validate([
            'file'   => 'required|file|max:51200|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,png,jpg,jpeg,gif,zip',
            'folder' => 'nullable|in:' . implode(',', self::FOLDERS),
        ]);
        $folder = $request->input('folder', 'General');

        // Keep the original name, but never overwrite an existing file.
        $original = preg_replace('/[^\w.\- ()]/', '_', $request->file('file')->getClientOriginalName());
        $name = $original;
        $i = 1;
        while (Storage::disk('local')->exists("documents/{$folder}/{$name}")) {
            $info = pathinfo($original);
            $name = $info['filename'] . " ({$i})" . (isset($info['extension']) ? ".{$info['extension']}" : '');
            $i++;
        }

        $path = $request->file('file')->storeAs("documents/{$folder}", $name, 'local');

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'upload_document',
            'metadata' => ['path' => $path],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['path' => $path, 'name' => basename($path), 'folder' => $folder], 201);
    }

    public function download(Request $request)
    {
        if ($deny = $this->denyNonInternal($request)) return $deny;

        $request->validate(['path' => 'required|string']);
        $path = $request->input('path');
        if (str_contains($path, '..') || ! str_starts_with($path, 'documents/')) {
            return response()->json(['message' => 'Invalid path'], 422);
        }
        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return Storage::disk('local')->download($path, basename($path));
    }

    public function destroy(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'partner', 'manager'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate(['path' => 'required|string']);

        // Only files inside the documents/ folder may be deleted.
        $path = $request->path;
        if (str_contains($path, '..') || ! str_starts_with($path, 'documents/')) {
            return response()->json(['message' => 'Invalid path'], 422);
        }

        Storage::disk('local')->delete($path);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'delete_document',
            'metadata' => ['path' => $path],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }
}
