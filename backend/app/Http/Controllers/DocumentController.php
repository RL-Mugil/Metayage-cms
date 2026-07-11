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
    private const INTERNAL_ROLES = ['super_admin', 'partner', 'manager', 'associate', 'paralegal', 'finance', 'hr'];

    private function denyNonInternal(Request $request)
    {
        if (! in_array($request->user()->role, self::INTERNAL_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    /** Resolve the client record for a portal user, or null for internal users. */
    private function clientFor(Request $request): ?\App\Models\Client
    {
        $user = $request->user();
        if (! $user->isClientRole()) return null;
        return $request->attributes->get('portal_client') ?? \App\Models\Client::forUser($user);
    }

    public const FOLDERS = ['General', 'Patents', 'Trademarks', 'Contracts', 'Correspondence', 'Invoices'];

    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->isClientRole()) {
            // Client portal users see only documents tagged to their client.
            $client = $this->clientFor($request);
            if (! $client) return response()->json(['message' => 'Forbidden'], 403);
        } elseif ($deny = $this->denyNonInternal($request)) {
            return $deny;
        }

        $perPage = max(1, min(500, (int) $request->query('per_page', 50)));
        $folder  = $request->query('folder');

        $query = Document::with('uploader:id,name')->orderBy('updated_at', 'desc');

        if ($user->isClientRole()) {
            // Client portal: own docs only
            $query->where('client_id', $client->id);
        } elseif (in_array($user->role, ['super_admin', 'partner', 'paralegal'])) {
            // These roles see everything — no filter needed
        } elseif ($user->role === 'associate') {
            // See docs for projects assigned to them, or un-tagged (internal) docs
            $assignedProjectIds = \App\Models\Project::where(function ($q) use ($user) {
                $q->where('patent_engineer_id', $user->id)
                  ->orWhere('assigned_manager_id', $user->id)
                  ->orWhere('secondary_manager_id', $user->id)
                  ->orWhereHas('tasks', fn ($t) => $t->where('assignee_id', $user->id));
            })->pluck('id');
            $query->where(function ($q) use ($assignedProjectIds) {
                $q->whereNull('project_id')
                  ->orWhereIn('project_id', $assignedProjectIds);
            })->where(function ($q) use ($user) {
                // Also scope to their own client or internal docs
                $q->whereNull('client_id')->orWhereHas('project', fn ($p) => $p->where(function ($pp) use ($user) {
                    $pp->where('patent_engineer_id', $user->id)
                       ->orWhere('assigned_manager_id', $user->id)
                       ->orWhere('secondary_manager_id', $user->id);
                }));
            });
        } elseif ($user->role === 'manager') {
            // See docs for projects they manage, or internal docs
            $managedProjectIds = \App\Models\Project::where('assigned_manager_id', $user->id)->pluck('id');
            $query->where(function ($q) use ($managedProjectIds) {
                $q->whereNull('client_id')
                  ->orWhereIn('project_id', $managedProjectIds);
            });
        } elseif (in_array($user->role, ['finance', 'hr'])) {
            // Internal-only docs (not tagged to a specific client)
            $query->whereNull('client_id');
        }
        // else: any other role falls through to see all (safe fallback)

        // Allow filtering by client_id (for roles that can see all)
        if ($request->filled('client_id') && in_array($user->role, ['super_admin', 'partner', 'paralegal', 'manager'])) {
            $cid = (int) $request->client_id;
            $query->where(function ($q) use ($cid) {
                $q->where('client_id', $cid)
                  ->orWhereHas('project', fn ($p) => $p->where('client_id', $cid));
            });
        }

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
        $user = $request->user();
        $ownClient = null;
        if ($user->isClientRole()) {
            $ownClient = $this->clientFor($request);
            if (! $ownClient) return response()->json(['message' => 'Forbidden'], 403);
        } elseif ($deny = $this->denyNonInternal($request)) {
            return $deny;
        }

        $request->validate([
            'file'       => 'required|file|max:51200|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,png,jpg,jpeg,gif,zip',
            'folder'     => 'nullable|in:' . implode(',', self::FOLDERS),
            'project_id' => 'nullable|exists:projects,id',
            'client_id'  => 'nullable|exists:clients,id',
        ]);

        // Client uploads are always tagged to their own client record.
        if ($ownClient) {
            $request->merge(['client_id' => $ownClient->id, 'project_id' => null]);
        }

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
        $user = $request->user();
        if ($user->isClientRole()) {
            $client = $this->clientFor($request);
            if (! $client) return response()->json(['message' => 'Forbidden'], 403);
        } elseif ($deny = $this->denyNonInternal($request)) {
            return $deny;
        }

        $request->validate(['path' => 'required|string']);
        $path = $request->input('path');
        if (str_contains($path, '..') || ! str_starts_with($path, 'documents/')) {
            return response()->json(['message' => 'Invalid path'], 422);
        }

        // Clients may only download documents tagged to their own client.
        if ($user->isClientRole()) {
            $doc = Document::where('storage_path', $path)->first();
            if (! $doc || (int) $doc->client_id !== (int) $client->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        // Scope check: associates/paralegals may only download documents whose
        // project they are assigned to. Managers/partners/admins are unrestricted.
        if ($user->role === 'associate') {
            $doc = Document::where('storage_path', $path)->first();
            if ($doc && $doc->project_id) {
                $project = \App\Models\Project::find($doc->project_id);
                $canAccess = $project && (
                    $project->patent_engineer_id  === $user->id ||
                    $project->assigned_manager_id === $user->id ||
                    $project->secondary_manager_id === $user->id ||
                    $project->tasks()->where('assignee_id', $user->id)->exists()
                );
                if (! $canAccess) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }
            }
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

        $doc = Document::where('storage_path', $path)->first();
        if ($doc) {
            // Managers may only delete documents belonging to projects they manage.
            if ($user->role === 'manager' && $doc->project_id) {
                $project = \App\Models\Project::find($doc->project_id);
                $team    = $project->assigned_team ?? [];
                $canDelete = $project && (
                    $project->assigned_manager_id === $user->id ||
                    in_array((string) $user->id, array_map('strval', $team))
                );
                if (! $canDelete) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }
            }

            // Hard-delete version records (DocumentVersion has no SoftDeletes).
            // Physical files are preserved so the document can be restored from
            // the soft-deleted Document record if needed.
            $doc->versions()->delete();
            $doc->delete(); // soft-delete: sets deleted_at, leaves files on disk
        } else {
            // No DB record — delete the orphaned file directly.
            Storage::disk('local')->delete($path);
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
