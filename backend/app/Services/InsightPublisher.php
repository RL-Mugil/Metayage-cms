<?php

namespace App\Services;

use App\Models\CpiArticle;
use Illuminate\Support\Facades\File;

/**
 * Publishes admin-written articles as static HTML on canpatentit.com so they are fast and indexable.
 * The page frame (header/footer) comes from resources/cpi/article-shell.html, which
 * tools/cpi-site/build.py regenerates whenever the site navigation changes.
 */
class InsightPublisher
{
    public static function sitePath(): string
    {
        return rtrim((string) config('services.cpi.site_path', '/var/www/canpatentit'), '/');
    }

    public function publish(CpiArticle $a): void
    {
        $dir = self::sitePath().'/insights';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$a->slug.'.html', $this->render($a));
        $this->rebuild();
    }

    public function unpublish(CpiArticle $a): void
    {
        $file = self::sitePath().'/insights/'.$a->slug.'.html';
        if (File::exists($file)) {
            File::delete($file);
        }
        $this->rebuild();
    }

    /** Rewrites the article list (JSON for the index page and search) and the articles sitemap. */
    public function rebuild(): void
    {
        $dir = self::sitePath().'/insights';
        File::ensureDirectoryExists($dir);
        $rows = CpiArticle::where('status', 'published')->orderByDesc('published_at')->get();

        File::put($dir.'/index.json', json_encode($rows->map(fn ($r) => [
            'slug' => $r->slug, 'title' => $r->title, 'excerpt' => $r->excerpt,
            'date_label' => $r->published_at?->format('j M Y'), 'published_at' => $r->published_at?->toIso8601String(),
        ])->values(), JSON_UNESCAPED_UNICODE));

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($rows as $r) {
            $xml .= '  <url><loc>https://canpatentit.com/insights/'.e($r->slug).'</loc><lastmod>'.($r->updated_at ?? now())->format('Y-m-d').'</lastmod></url>'."\n";
        }
        File::put($dir.'/sitemap-insights.xml', $xml.'</urlset>'."\n");
    }

    public function render(CpiArticle $a): string
    {
        $shell = File::get(resource_path('cpi/article-shell.html'));
        $title = $a->meta_title ?: $a->title;
        $desc = $a->meta_description ?: ($a->excerpt ?: $a->title);
        $url = 'https://canpatentit.com/insights/'.$a->slug;
        $date = $a->published_at?->format('j F Y') ?? '';

        $body = '<article class="cpi-article"><nav class="cpi-crumbs" aria-label="Breadcrumb"><a href="/insights">Insights</a></nav>'
            .'<h1>'.e($a->title).'</h1><p class="meta">'.e(trim($date.($a->author ? ' · '.$a->author : ''), ' ·')).'</p>'
            .'<div class="cpi-prose">'.$a->body_html.'</div>'
            .'<p class="cpi-fine">General information only. Not legal advice, and no attorney–client relationship is created by reading this article.</p>'
            .'<p><a class="cpi-button-lime" href="/book">Book a consultation</a></p></article>';

        $ld = json_encode([
            '@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $a->title, 'description' => $desc,
            'datePublished' => $a->published_at?->toIso8601String(), 'dateModified' => $a->updated_at?->toIso8601String(),
            'author' => ['@type' => 'Organization', 'name' => $a->author ?: 'CanPatentIt'], 'mainEntityOfPage' => $url,
            'publisher' => ['@type' => 'Organization', 'name' => 'CanPatentIt', 'logo' => ['@type' => 'ImageObject', 'url' => 'https://canpatentit.com/assets/logo.png']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // A closing tag inside JSON would end the script element early.
        $ld = str_replace('</', '<\/', (string) $ld);

        return strtr($shell, [
            '@@TITLE@@' => e($title),
            '@@DESC@@' => e($desc),
            '@@SLUG@@' => e($a->slug),
            '<!--@@HEAD@@-->' => '<script type="application/ld+json">'.$ld.'</script>',
            '@@BODY@@' => $body,
        ]);
    }
}
