<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CpiPatent;
use App\Services\InsightPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Admin CRUD for the patent showcase on canpatentit.com. Publishing writes static pages and the list JSON. */
class CpiPatentController extends Controller
{
    public function __construct(private InsightPublisher $publisher)
    {
    }

    public function index(): JsonResponse
    {
        Gate::authorize('manage-site');

        return response()->json(CpiPatent::orderByDesc('updated_at')->get());
    }

    public function show(int $id): JsonResponse
    {
        Gate::authorize('manage-site');

        return response()->json(CpiPatent::findOrFail($id));
    }

    /** Domain suggestions: the standard list plus any domain already used. */
    public function domains(): JsonResponse
    {
        Gate::authorize('manage-site');
        $used = CpiPatent::query()->distinct()->pluck('domain')->all();

        return response()->json(collect(CpiPatent::DOMAINS)->merge($used)->unique()->sort()->values());
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['title']);
        $data['status'] = 'draft';
        $patent = CpiPatent::create($data);
        $this->audit($request, 'cpi_patent_create', $patent);

        return response()->json($patent, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $patent = CpiPatent::findOrFail($id);
        $data = $this->validated($request, $patent->id);
        $before = $this->publisher->patentImageNames($patent);
        $patent->update($data); // the slug is a public URL and never changes after creation
        if ($patent->status === 'published') {
            $this->publisher->publishPatent($patent->fresh());
        }
        // Figures removed or replaced by this edit are deleted unless another patent or article still uses them.
        $this->publisher->releaseImages(array_diff($before, $this->publisher->patentImageNames($patent->fresh())), null, $patent->id);
        $this->audit($request, 'cpi_patent_update', $patent);

        return response()->json($patent->fresh());
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $patent = CpiPatent::findOrFail($id);
        $patent->update(['status' => 'published', 'published_at' => $patent->published_at ?? now()]);
        $this->publisher->publishPatent($patent->fresh());
        $this->audit($request, 'cpi_patent_publish', $patent);

        return response()->json($patent->fresh());
    }

    public function unpublish(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $patent = CpiPatent::findOrFail($id);
        $patent->update(['status' => 'draft']);
        $this->publisher->unpublishPatent($patent);
        $this->audit($request, 'cpi_patent_unpublish', $patent);

        return response()->json($patent->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $patent = CpiPatent::findOrFail($id);
        $images = $this->publisher->patentImageNames($patent);
        $this->publisher->unpublishPatent($patent);
        $patent->delete();
        $this->publisher->rebuildPatents();
        $this->publisher->releaseImages($images);
        $this->audit($request, 'cpi_patent_delete', $patent);

        return response()->json(['message' => 'Deleted.']);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'patent_number' => ['required', 'string', 'min:3', 'max:60', Rule::unique('cpi_patents', 'patent_number')->ignore($ignoreId)],
            'title' => ['required', 'string', 'min:3', 'max:220'],
            'domain' => ['required', 'string', 'min:2', 'max:80'],
            'country' => ['required', Rule::in(CpiPatent::COUNTRIES)],
            'abstract' => ['required', 'string', 'min:10', 'max:8000'],
            'image' => ['nullable', 'string', 'max:150', 'regex:#^/insights/img/[a-z0-9][a-z0-9._-]{0,100}\.(?:webp|jpe?g|png|gif)$#i'],
            'image_alt' => ['nullable', 'string', 'max:200'],
            'figures' => ['nullable', 'array', 'max:'.CpiPatent::MAX_FIGURES],
            'figures.*.url' => ['required', 'string', 'max:150', 'regex:#^/insights/img/[a-z0-9][a-z0-9._-]{0,100}\.(?:webp|jpe?g|png|gif)$#i'],
            'figures.*.alt' => ['nullable', 'string', 'max:200'],
            // Optional override for the Patent ID link; blank means the automatic Google Patents link.
            'link_url' => ['nullable', 'string', 'max:300', 'regex:#^https://[^\s<>"\']+$#i'],
            'link_disabled' => ['sometimes', 'boolean'],
        ]);

        // The first figure is also the grid thumbnail (image / image_alt). A lone legacy "image" still works.
        $figures = collect($data['figures'] ?? [])->map(fn ($f) => ['url' => $f['url'], 'alt' => isset($f['alt']) ? trim((string) $f['alt']) ?: null : null])->values()->all();
        if (! $figures && ! empty($data['image'])) {
            $figures = [['url' => $data['image'], 'alt' => $data['image_alt'] ?? null]];
        }
        $data['figures'] = $figures;
        $data['image'] = $figures[0]['url'] ?? null;
        $data['image_alt'] = $figures[0]['alt'] ?? null;
        $data['link_url'] = isset($data['link_url']) ? trim($data['link_url']) ?: null : null;
        foreach (['patent_number', 'title', 'domain'] as $k) {
            $data[$k] = trim(preg_replace('/\s+/', ' ', $data[$k]));
        }
        $data['abstract'] = trim(str_replace("\r\n", "\n", $data['abstract']));

        return $data;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 100, '') ?: 'patent';
        $slug = $base;
        $i = 2;
        while (CpiPatent::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function audit(Request $request, string $action, CpiPatent $p): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => $action, 'subject_type' => CpiPatent::class,
            'subject_id' => $p->id, 'metadata' => ['patent_number' => $p->patent_number, 'title' => $p->title], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }
}
