<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CpiArticle;
use App\Services\InsightPublisher;
use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
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
        $before = $this->publisher->imageNames($article);
        $article->update($data);
        if ($article->status === 'published') {
            $this->publisher->publish($article->fresh());
        }
        // Pictures removed or replaced by this edit are deleted unless another article uses them.
        $this->publisher->releaseImages(array_diff($before, $this->publisher->imageNames($article->fresh())), $article->id);
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
        $images = $this->publisher->imageNames($article);
        $this->publisher->unpublish($article);
        $article->delete();
        $this->publisher->rebuild();
        $this->publisher->releaseImages($images); // the article is gone, so only other articles can still need them
        $this->audit($request, 'cpi_article_delete', $article);

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Stores an image for articles. Decoded and re-encoded server-side (drops metadata and anything
     * that is not really an image), capped at 1600px wide, and named by content hash.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');
        $request->validate(['image' => ['required', 'file', 'max:1024', 'mimes:jpg,jpeg,png,webp,gif']]);

        $raw = (string) file_get_contents($request->file('image')->getRealPath());
        $info = @getimagesizefromstring($raw);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            return response()->json(['message' => 'That file is not a supported image.'], 422);
        }
        if ($info[0] * $info[1] > 40_000_000) {
            return response()->json(['message' => 'That image is too large.'], 422);
        }

        $src = @imagecreatefromstring($raw);
        if (! $src) {
            return response()->json(['message' => 'That image could not be read.'], 422);
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w > 1600) {
            $src = imagescale($src, 1600, (int) round($h * 1600 / $w));
            $w = imagesx($src);
            $h = imagesy($src);
        }
        imagepalettetotruecolor($src);
        imagealphablending($src, true);
        imagesavealpha($src, true);

        ob_start();
        imagewebp($src, null, 82);
        $encoded = (string) ob_get_clean();
        if ($encoded === '') {
            return response()->json(['message' => 'The image could not be processed.'], 422);
        }

        $name = substr(hash('sha256', $encoded), 0, 24).'.webp';
        $dir = InsightPublisher::sitePath().'/insights/img';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$name, $encoded);

        return response()->json(['url' => '/insights/img/'.$name, 'width' => $w, 'height' => $h], 201);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'excerpt' => ['nullable', 'string', 'max:320'],
            'cover_image' => ['nullable', 'string', 'max:150', 'regex:#^/insights/img/[a-z0-9][a-z0-9._-]{0,100}\.(?:webp|jpe?g|png|gif)$#i'],
            'cover_alt' => ['nullable', 'string', 'max:200'],
            'body_html' => ['required', 'string', 'max:200000'],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:200'],
            'author' => ['nullable', 'string', 'max:100'],
        ]);
        // Rendered as-is on a public page, so only allow-listed markup survives.
        $data['body_html'] = HtmlSanitizer::clean($data['body_html'], true);
        if (empty($data['excerpt'])) {
            // News-style preview: the first sentences of the body when no summary was written.
            $text = preg_replace('/<\/(p|h[1-4]|li|div|blockquote|figcaption)>/i', ' ', $data['body_html']);
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5)));
            $data['excerpt'] = $text !== '' ? Str::limit($text, 200, '…') : null;
        }

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
