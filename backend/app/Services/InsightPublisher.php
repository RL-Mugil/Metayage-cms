<?php

namespace App\Services;

use App\Models\CpiArticle;
use App\Models\CpiFaq;
use App\Models\CpiPatent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

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

    /** Service and technology pages that carry their own "Common questions" block: slug => [path, label]. */
    public const PAGE_FAQS = [
        'patentability-prior-art' => ['services/patentability-prior-art', 'Service: Patentability & prior art'],
        'provisional-applications' => ['services/provisional-applications', 'Service: Provisional applications'],
        'us-patent-prosecution' => ['services/us-patent-prosecution', 'Service: US patent prosecution'],
        'ip-strategy-diligence' => ['services/ip-strategy-diligence', 'Service: IP strategy & diligence'],
        'ai-machine-learning' => ['technology/ai-machine-learning', 'Technology: AI & machine learning'],
        'semiconductors-quantum' => ['technology/semiconductors-quantum', 'Technology: Semiconductors & quantum'],
        'medtech-biotechnology' => ['technology/medtech-biotechnology', 'Technology: MedTech & biotechnology'],
        'climate-energy' => ['technology/climate-energy', 'Technology: Climate & energy'],
        'enterprise-software-security' => ['technology/enterprise-software-security', 'Technology: Enterprise software & security'],
    ];

    /** @return array{0: string, 1: string} [block html, FAQPage json-ld script or ''] */
    private function faqMarkup($faqs, string $heading, string $empty): array
    {
        $rows = '';
        $entities = [];
        foreach ($faqs as $f) {
            $paragraphs = collect(preg_split('/\n{2,}/', trim($f->answer)))->filter()
                ->map(fn ($p) => '<p>'.nl2br(e(trim($p)), false).'</p>')->implode('');
            $rows .= '<details class="cpi-faq"><summary>'.e($f->question).'</summary><div>'.$paragraphs.'</div></details>';
            $entities[] = ['@type' => 'Question', 'name' => $f->question, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f->answer]];
        }
        $block = $rows === '' ? $empty : '<div class="cpi-faq-list"><h2>'.e($heading).'</h2>'.$rows.'</div>';
        $ld = $entities
            ? '<script type="application/ld+json">'.str_replace('</', '<\/', (string) json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'</script>'
            : '';

        return [$block, $ld];
    }

    /** Rewrites the main FAQ page from the questions saved in the admin panel. */
    public function publishFaq(): void
    {
        $faqs = CpiFaq::whereNull('page')->where('active', true)->orderBy('sort_order')->orderBy('id')->get();
        [$block, $ld] = $this->faqMarkup($faqs, 'Frequently asked questions', '<div class="cpi-faq-list"><h2>Frequently asked questions</h2><p>No questions yet.</p></div>');

        $html = strtr(File::get(resource_path('cpi/faq-shell.html')), ['@@FAQ_BLOCK@@' => $block, '<!--@@FAQ_LD@@-->' => $ld]);
        File::ensureDirectoryExists(self::sitePath());
        File::put(self::sitePath().'/faq.html', $html);
    }

    /** Rewrites only the "Common questions" block (and its markup) inside one service or technology page. */
    public function publishPageFaq(string $slug): void
    {
        if (! isset(self::PAGE_FAQS[$slug])) {
            return;
        }
        $file = self::sitePath().'/'.self::PAGE_FAQS[$slug][0].'.html';
        if (! File::exists($file)) {
            return;
        }
        $faqs = CpiFaq::where('page', $slug)->where('active', true)->orderBy('sort_order')->orderBy('id')->get();
        [$block, $ld] = $this->faqMarkup($faqs, 'Common questions', '');

        $html = File::get($file);
        $html = preg_replace_callback('~<!--cpi:faq-->.*?<!--/cpi:faq-->~s', fn () => '<!--cpi:faq-->'.$block.'<!--/cpi:faq-->', $html);
        $html = preg_replace_callback('~<!--cpi:faqld-->.*?<!--/cpi:faqld-->~s', fn () => '<!--cpi:faqld-->'.$ld.'<!--/cpi:faqld-->', $html);
        File::put($file, $html);
    }

    /** Republishes whichever page a question belongs to (null = the main FAQ page). */
    public function publishFaqFor(?string $page): void
    {
        $page === null ? $this->publishFaq() : $this->publishPageFaq($page);
    }

    /** FAQ page plus every published article, e.g. after the site header or footer changed. */
    public function republishAll(): int
    {
        $this->publishFaq();
        foreach (array_keys(self::PAGE_FAQS) as $slug) {
            $this->publishPageFaq($slug);
        }
        foreach (CpiPatent::where('status', 'published')->get() as $patent) {
            $this->writePatentPage($patent);
        }
        $this->rebuildPatents();
        $articles = CpiArticle::where('status', 'published')->get();
        foreach ($articles as $a) {
            File::ensureDirectoryExists(self::sitePath().'/insights');
            File::put(self::sitePath().'/insights/'.$a->slug.'.html', $this->render($a));
        }
        $this->rebuild();

        return $articles->count();
    }

    // ---- Patent showcase ----

    public function publishPatent(CpiPatent $p): void
    {
        $this->writePatentPage($p);
        $this->rebuildPatents();
    }

    public function unpublishPatent(CpiPatent $p): void
    {
        $file = self::sitePath().'/patent-showcase/'.$p->slug.'.html';
        if (File::exists($file)) {
            File::delete($file);
        }
        $this->rebuildPatents();
    }

    /** Rewrites the list JSON (filters, grid and recommendations read it) and the patent sitemap. */
    public function rebuildPatents(): void
    {
        $dir = self::sitePath().'/patent-showcase';
        File::ensureDirectoryExists($dir);
        $rows = CpiPatent::where('status', 'published')->orderByDesc('published_at')->orderByDesc('id')->get();

        File::put($dir.'/index.json', json_encode($rows->map(fn ($r) => [
            'slug' => $r->slug, 'id' => $r->patent_number, 'title' => $r->title, 'domain' => $r->domain, 'country' => $r->country,
            'image' => $r->image, 'image_alt' => $r->image_alt ?: $r->title,
        ])->values(), JSON_UNESCAPED_UNICODE));

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($rows as $r) {
            $xml .= '  <url><loc>https://canpatentit.com/patent-showcase/'.e($r->slug).'</loc><lastmod>'.($r->updated_at ?? now())->format('Y-m-d').'</lastmod></url>'."\n";
        }
        File::put($dir.'/sitemap-patents.xml', $xml.'</urlset>'."\n");
    }

    private function writePatentPage(CpiPatent $p): void
    {
        $dir = self::sitePath().'/patent-showcase';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$p->slug.'.html', $this->renderPatent($p));
    }

    public function renderPatent(CpiPatent $p): string
    {
        $shell = File::get(resource_path('cpi/patent-shell.html'));
        $url = 'https://canpatentit.com/patent-showcase/'.$p->slug;
        $desc = Str::limit(trim(preg_replace('/\s+/', ' ', $p->abstract)), 190, '…');
        $paragraphs = collect(preg_split('/\n{2,}/', trim($p->abstract)))->filter()->map(fn ($t) => '<p>'.nl2br(e(trim($t)), false).'</p>')->implode('');
        $figure = $p->image
            ? '<figure class="cpi-patent-figure"><img src="'.e($p->image).'" alt="'.e($p->image_alt ?: $p->title).'"></figure>'
            : '';

        $body = '<article class="cpi-patent" data-slug="'.e($p->slug).'" data-domain="'.e($p->domain).'" data-country="'.e($p->country).'">'
            .'<nav class="cpi-crumbs" aria-label="Breadcrumb"><a href="/patent-showcase">Patent showcase</a></nav>'
            .'<div class="cpi-patent-head"><dl class="cpi-patent-meta">'
            .'<div><dt>Patent ID</dt><dd>'.e($p->patent_number).'</dd></div>'
            .'<div><dt>Domain</dt><dd><a href="/patent-showcase?domain='.rawurlencode($p->domain).'">'.e($p->domain).'</a></dd></div>'
            .'<div><dt>Country</dt><dd><a href="/patent-showcase?country='.rawurlencode($p->country).'">'.e($p->country).'</a></dd></div>'
            .'</dl><h1>'.e($p->title).'</h1></div>'
            .$figure
            .'<section class="cpi-patent-abstract"><h2>Abstract</h2><div class="cpi-prose">'.$paragraphs.'</div></section>'
            .'<p class="cpi-fine">Shown as an example of the work Metayage and CanPatentIt do. Not legal advice, and no outcome is guaranteed for any other invention.</p>'
            .'<section class="cpi-patent-recs" id="recs" hidden><h2>Recommended patents</h2><div class="cpi-post-grid cpi-rec-grid" id="rec-grid"></div></section>'
            .'<p><a class="cpi-button-lime" href="/book">Book a consultation</a></p></article>';

        $ld = str_replace('</', '<\/', (string) json_encode([
            '@context' => 'https://schema.org', '@type' => 'CreativeWork', 'name' => $p->title, 'identifier' => $p->patent_number,
            'description' => $desc, 'about' => $p->domain, 'url' => $url,
            'image' => $p->image ? 'https://canpatentit.com'.$p->image : 'https://canpatentit.com/assets/og-image.png',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return strtr($shell, [
            '@@TITLE@@' => e($p->title),
            '@@DESC@@' => e($desc),
            '@@SLUG@@' => e($p->slug),
            '@@OGIMAGE@@' => $p->image ? 'https://canpatentit.com'.e($p->image) : 'https://canpatentit.com/assets/og-image.png',
            '<!--@@HEAD@@-->' => '<script type="application/ld+json">'.$ld.'</script>',
            '@@BODY@@' => $body,
        ]);
    }

    /** File names of every uploaded image an article uses (cover and inline). */
    public function imageNames(CpiArticle $a): array
    {
        $names = [];
        if ($a->cover_image) {
            $names[] = basename($a->cover_image);
        }
        preg_match_all('#/insights/img/([a-z0-9][a-z0-9._-]*\.(?:webp|jpe?g|png|gif))#i', (string) $a->body_html, $m);

        return array_values(array_unique(array_merge($names, $m[1])));
    }

    /** Deletes the given images unless some other article still uses them. Returns how many were removed. */
    public function releaseImages(array $names, ?int $exceptArticleId = null, ?int $exceptPatentId = null): int
    {
        $names = array_values(array_unique(array_filter($names)));
        if (! $names) {
            return 0;
        }
        $inUse = [];
        CpiArticle::query()->when($exceptArticleId, fn ($q) => $q->where('id', '!=', $exceptArticleId))->get(['id', 'cover_image', 'body_html'])
            ->each(function ($other) use (&$inUse) {
                foreach ($this->imageNames($other) as $n) {
                    $inUse[$n] = true;
                }
            });
        // Patent showcase figures share the same image folder.
        CpiPatent::query()->when($exceptPatentId, fn ($q) => $q->where('id', '!=', $exceptPatentId))->whereNotNull('image')->pluck('image')
            ->each(function ($img) use (&$inUse) {
                $inUse[basename($img)] = true;
            });

        $removed = 0;
        foreach ($names as $n) {
            $path = self::sitePath().'/insights/img/'.$n;
            if (! isset($inUse[$n]) && preg_match('#^[a-z0-9][a-z0-9._-]*$#i', $n) && File::exists($path)) {
                File::delete($path);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Safety net for stray uploads (picked in the editor but never saved): removes every file in the
     * image folder that no article references, once it is older than the grace period.
     */
    public function pruneImages(int $graceMinutes = 60): int
    {
        $dir = self::sitePath().'/insights/img';
        if (! File::isDirectory($dir)) {
            return 0;
        }
        $used = [];
        CpiArticle::query()->get(['cover_image', 'body_html'])->each(function ($a) use (&$used) {
            foreach ($this->imageNames($a) as $n) {
                $used[$n] = true;
            }
        });
        CpiPatent::query()->whereNotNull('image')->pluck('image')->each(function ($img) use (&$used) {
            $used[basename($img)] = true;
        });

        $removed = 0;
        foreach (File::files($dir) as $file) {
            if (isset($used[$file->getFilename()]) || $file->getMTime() > time() - $graceMinutes * 60) {
                continue;
            }
            File::delete($file->getPathname());
            $removed++;
        }

        return $removed;
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
            'cover' => $r->cover_image, 'cover_alt' => $r->cover_alt ?: $r->title,
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

        $cover = $a->cover_image
            ? '<figure class="cpi-article-cover"><img src="'.e($a->cover_image).'" alt="'.e($a->cover_alt ?: $a->title).'"></figure>'
            : '';
        $body = '<article class="cpi-article"><nav class="cpi-crumbs" aria-label="Breadcrumb"><a href="/insights">Insights</a></nav>'
            .'<h1>'.e($a->title).'</h1><p class="meta">'.e(trim($date.($a->author ? ' · '.$a->author : ''), ' ·')).'</p>'
            .$cover
            .'<div class="cpi-prose">'.$a->body_html.'</div>'
            .'<p class="cpi-fine">General information only. Not legal advice, and no attorney–client relationship is created by reading this article.</p>'
            .'<p><a class="cpi-button-lime" href="/book">Book a consultation</a></p></article>';

        $ld = json_encode([
            '@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $a->title, 'description' => $desc,
            'datePublished' => $a->published_at?->toIso8601String(), 'dateModified' => $a->updated_at?->toIso8601String(),
            'image' => $a->cover_image ? 'https://canpatentit.com'.$a->cover_image : 'https://canpatentit.com/assets/og-image.png',
            'author' => ['@type' => 'Organization', 'name' => $a->author ?: 'CanPatentIt'], 'mainEntityOfPage' => $url,
            'publisher' => ['@type' => 'Organization', 'name' => 'CanPatentIt', 'logo' => ['@type' => 'ImageObject', 'url' => 'https://canpatentit.com/assets/logo.png']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // A closing tag inside JSON would end the script element early.
        $ld = str_replace('</', '<\/', (string) $ld);

        return strtr($shell, [
            '@@TITLE@@' => e($title),
            '@@DESC@@' => e($desc),
            '@@SLUG@@' => e($a->slug),
            '@@OGIMAGE@@' => $a->cover_image ? 'https://canpatentit.com'.e($a->cover_image) : 'https://canpatentit.com/assets/og-image.png',
            '<!--@@HEAD@@-->' => '<script type="application/ld+json">'.$ld.'</script>',
            '@@BODY@@' => $body,
        ]);
    }
}
