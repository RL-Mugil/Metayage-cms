<?php
namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $folder  = $request->query('folder');

        $query = Document::with('uploader:id,name')->orderBy('updated_at', 'desc');

        if ($folder && in_array($folder, self::FOLDERS)) {
            $query->where('category', $folder);
        }

        $paginated = $query->paginate($perPage, ['*'], 'page', max(1, (int) $request->query('page', 1)));

        return response()->json([
            'data' => collect($paginated->items())->map(fn($doc) => [
                'id'       => $doc->id,
                'name'     => $doc->file_name,
                'path'     => $doc->storage_path,
                'folder'   => $doc->category,
                'size'     => $doc->file_size,
                'uploader' => $doc->uploader?->name,
                'modified' => $doc->updated_at->toDateTimeString(),
            ]),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'has_more'     => $paginated->hasMorePages(),
        ]);
    }

    public function store(Request $request)
    {
        if ($deny = $this->denyNonInternal($request)) return $deny;

        $request->validate([
            'file'       => 'required|file|max:51200|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,png,jpg,jpeg,gif,zip',
            'folder'     => 'nullable|in:' . implode(',', self::FOLDERS),
            'project_id' => 'nullable|exists:projects,id',
            'client_id'  => 'nullable|exists:clients,id',
        ]);

        $file   = $request->file('file');
        $folder = $request->input('folder', 'General');

        // Preserve original filename; avoid overwriting existing files
        $original = preg_replace('/[^\w.\- ()]/', '_', $file->getClientOriginalName());
        $name = $original;
        $i = 1;
        while (Storage::disk('local')->exists("documents/{$folder}/{$name}")) {
            $info = pathinfo($original);
            $name = $info['filename'] . " ({$i})" . (isset($info['extension']) ? ".{$info['extension']}" : '');
            $i++;
        }

        $path = $file->storeAs("documents/{$folder}", $name, 'local');

        $document = DB::transaction(function () use ($request, $file, $original, $path, $folder) {
            $doc = Document::create([
                'project_id'      => $request->project_id,
                'client_id'       => $request->client_id,
                'file_name'       => $original,
                'file_type'       => $file->getClientMimeType(),
                'file_size'       => $file->getSize(),
                'category'        => $folder,
                'storage_path'    => $path,
                'current_version' => 1,
                'uploaded_by_id'  => $request->user()->id,
                'status'          => 'Draft',
            ]);

            DocumentVersion::create([
                'document_id'    => $doc->id,
                'version_number' => 1,
                'file_name'      => $original,
                'file_size'      => $file->getSize(),
                'storage_path'   => $path,
                'uploaded_by_id' => $request->user()->id,
            ]);

            AuditLog::create([
                'user_id'      => $request->user()->id,
                'action'       => 'upload_document',
                'subject_type' => 'Document',
                'subject_id'   => $doc->id,
                'metadata'     => ['path' => $path, 'file_name' => $original],
                'ip_address'   => $request->ip(),
                'user_agent'   => $request->userAgent(),
            ]);

            return $doc;
        });

        return response()->json([
            'id'     => $document->id,
            'path'   => $path,
            'name'   => $original,
            'folder' => $folder,
        ], 201);
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
        $path = $request->path;

        if (str_contains($path, '..') || ! str_starts_with($path, 'documents/')) {
            return response()->json(['message' => 'Invalid path'], 422);
        }

        Storage::disk('local')->delete($path);

        // Soft-delete the DB record if it exists
        $doc = Document::where('storage_path', $path)->first();
        if ($doc) {
            $doc->delete();
        }

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'delete_document',
            'subject_type' => 'Document',
            'subject_id'   => $doc?->id,
            'metadata'     => ['path' => $path],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }
}
