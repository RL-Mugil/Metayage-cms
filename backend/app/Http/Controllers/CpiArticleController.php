<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CpiArticle;
use App\Services\InsightPublisher;
use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Admin CRUD for canpatentit.com articles. Publishing writes static HTML via InsightPublisher. */
class CpiArticleController extends Controller
{
    public function __construct(private InsightPublisher $publisher)
    {
    }

    public function index(): JsonResponse
    {
        Gate::authorize('manage-site');

        return response()->json(CpiArticle::orderByDesc('updated_at')->get(['id', 'title', 'slug', 'status', 'published_at', 'updated_at']));
    }

    public function show(int $id): JsonResponse
    {
        Gate::authorize('manage-site');

        return response()->json(CpiArticle::findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['title']);
        $data['status'] = 'draft';
        $article = CpiArticle::create($data);
        $this->audit($request, 'cpi_article_create', $article);

        return response()->json($article, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $article = CpiArticle::findOrFail($id);
        $data = $this->validated($request);
        // A published slug is a public URL; only drafts may change it.
        if ($article->status === 'draft' && ! empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $article->id);
        } else {
            unset($data['slug']);
        }
        $article->update($data);
        if ($article->status === 'published') {
            $this->publisher->publish($article->fresh());
        }
        $this->audit($request, 'cpi_article_update', $article);

        return response()->json($article->fresh());
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $article = CpiArticle::findOrFail($id);
        $article->update(['status' => 'published', 'published_at' => $article->published_at ?? now()]);
        $this->publisher->publish($article->fresh());
        $this->audit($request, 'cpi_article_publish', $article);

        return response()->json($article->fresh());
    }

    public function unpublish(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $article = CpiArticle::findOrFail($id);
        $article->update(['status' => 'draft']);
        $this->publisher->unpublish($article);
        $this->audit($request, 'cpi_article_unpublish', $article);

        return response()->json($article->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $article = CpiArticle::findOrFail($id);
        $this->publisher->unpublish($article);
        $article->delete();
        $this->publisher->rebuild();
        $this->audit($request, 'cpi_article_delete', $article);

        return response()->json(['message' => 'Deleted.']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'excerpt' => ['nullable', 'string', 'max:320'],
            'body_html' => ['required', 'string', 'max:200000'],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:200'],
            'author' => ['nullable', 'string', 'max:100'],
        ]);
        // Rendered as-is on a public page, so only allow-listed markup survives.
        $data['body_html'] = HtmlSanitizer::clean($data['body_html']);

        return $data;
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'article';
        $slug = $base;
        $i = 2;
        while (CpiArticle::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function audit(Request $request, string $action, CpiArticle $a): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => $action, 'subject_type' => CpiArticle::class,
            'subject_id' => $a->id, 'metadata' => ['title' => $a->title], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }
}
