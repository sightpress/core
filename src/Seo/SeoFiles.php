<?php

declare(strict_types=1);

namespace SightPress\Core\Seo;

use DateTimeZone;
use Psr\Cache\CacheItemPoolInterface;
use SightPress\Core\Page\Contract\IndexedPage;
use SightPress\Core\Page\Contract\MarkdownPages;
use SightPress\Core\Page\Contract\PageIndex;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\TenantConfig;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use XMLWriter;

/**
 * A site's `sitemap.xml`, `robots.txt`, `llms.txt` and `llms-full.txt`, kept in a cache of their own until a page changes ({@see purge()}).
 *
 * The sitemap lists every page search engines may index (published, public, not `noindex`, not a copy of
 * another page), in every language, with when it last changed and, on sites with more than one language, its other
 * languages as `xhtml:link` alternates. `robots.txt` keeps crawlers out of the admin, sign-in and account pages
 * and points them to the sitemap, and names the AI crawlers the site's {@see AiCrawlerPolicy} keeps out; on an
 * install that isn't in production it keeps them out of everything.
 */
final class SeoFiles
{
    /** `llms-full.txt` stops adding pages beyond this size, and says so. */
    public const int FULL_TEXT_MAX_BYTES = 500_000;

    /** Paths crawlers have no business in. */
    public const array PRIVATE_PATHS = ['/admin', '/account', '/login', '/logout', '/contact-form'];

    private ?CacheItemPoolInterface $cache;

    public function __construct(
        private readonly PageIndex $pages,
        private readonly RobotsPolicy $robots,
        private readonly Site $site,
        private readonly TenantConfig $config,
        private readonly AiCrawlerPolicy $aiCrawlers,
        private readonly MarkdownPages $documents,
        private readonly SiteSettings $settings,
        ?CacheItemPoolInterface $cache = null,
    ) {
        $this->cache = $cache;
    }

    /**
     * The sitemap: with one language, every page; with more, an index of one sitemap per language
     * ({@see localeSitemap()}).
     */
    public function sitemap(string $baseUrl): string
    {
        if (count($this->site->locales) < 2) {
            return $this->cached('sitemap', $baseUrl, fn (string $base): string => $this->buildSitemap($base, null));
        }

        return $this->cached('sitemap-index', $baseUrl, fn (string $base): string => $this->buildSitemapIndex($base));
    }

    /**
     * The sitemap of one of the site's languages (`/sitemap-pt-pt.xml`): its pages, each with every translation as an
     * alternate (itself included) and `x-default`. Null for a language the site doesn't have.
     */
    public function localeSitemap(string $baseUrl, string $locale): ?string
    {
        foreach ($this->site->locales as $own) {
            if (strtolower($own) === strtolower($locale)) {
                return $this->cached('sitemap-' . strtolower($own), $baseUrl, fn (string $base): string => $this->buildSitemap($base, $own));
            }
        }

        return null;
    }

    /**
     * Address of the sitemap of a language.
     */
    public static function sitemapPath(string $locale): string
    {
        return '/sitemap-' . strtolower($locale) . '.xml';
    }

    public function robots(string $baseUrl): string
    {
        return $this->cached('robots.' . $this->aiCrawlers->current(), $baseUrl, fn (string $base): string => $this->buildRobots($base));
    }

    /**
     * `llms.txt` (llmstxt.org): the site's name and summary, then a link to the Markdown version of every page search
     * engines may index, with its description.
     */
    public function llms(string $baseUrl): string
    {
        return $this->cached('llms', $baseUrl, fn (string $base): string => $this->buildLlms($base));
    }

    /**
     * `llms-full.txt`: the same pages in full, as Markdown, up to {@see FULL_TEXT_MAX_BYTES}.
     */
    public function llmsFull(string $baseUrl): string
    {
        return $this->cached('llms-full', $baseUrl, fn (string $base): string => $this->buildLlmsFull($base));
    }

    /**
     * The pages search engines may index.
     *
     * @return list<IndexedPage>
     */
    public function indexable(): array
    {
        return array_values(array_filter($this->published(), static fn (IndexedPage $page): bool => $page->isIndexable()));
    }

    /**
     * Every published page, indexable or not.
     *
     * @return list<IndexedPage>
     */
    public function published(): array
    {
        return $this->pages->published();
    }

    /**
     * Forgets the cached files, after a page changed.
     */
    public function purge(): void
    {
        $this->cache()->clear();
    }

    private function buildSitemapIndex(string $base): string
    {
        $latest = [];
        foreach ($this->indexable() as $page) {
            $latest[$page->locale] = max($latest[$page->locale] ?? $page->updatedAt, $page->updatedAt);
        }
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($this->site->locales as $locale) {
            $xml->startElement('sitemap');
            $xml->writeElement('loc', $base . self::sitemapPath($locale));
            if (isset($latest[$locale])) {
                $xml->writeElement('lastmod', $latest[$locale]->setTimezone(new DateTimeZone('UTC'))->format(DATE_W3C));
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function buildSitemap(string $base, ?string $only): string
    {
        $all = $this->indexable();
        $languages = [];
        foreach ($all as $page) {
            $languages[$page->id][$page->locale] = $base . $page->url;
        }
        $pages = $only === null ? $all : array_values(array_filter($all, static fn (IndexedPage $page): bool => $page->locale === $only));
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
        foreach ($pages as $page) {
            $xml->startElement('url');
            $xml->writeElement('loc', $base . $page->url);
            $xml->writeElement('lastmod', $page->updatedAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_W3C));
            $alternates = $languages[$page->id];
            if (count($alternates) > 1) {
                if (isset($alternates[$this->site->defaultLocale])) {
                    $alternates['x-default'] = $alternates[$this->site->defaultLocale];
                }
                foreach ($alternates as $locale => $href) {
                    $xml->startElement('xhtml:link');
                    $xml->writeAttribute('rel', 'alternate');
                    $xml->writeAttribute('hreflang', $locale);
                    $xml->writeAttribute('href', $href);
                    $xml->endElement();
                }
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function buildRobots(string $base): string
    {
        if (!$this->robots->isProduction()) {
            return "# This install is not in production: search engines are asked to stay away.\nUser-agent: *\nDisallow: /\n";
        }
        $lines = ['User-agent: *'];
        foreach (self::PRIVATE_PATHS as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        $lines[] = 'Allow: /';
        $blocked = $this->aiCrawlers->blocked();
        if ($blocked !== []) {
            $lines[] = '';
            $lines[] = '# AI crawlers kept out by the site\'s policy (' . $this->aiCrawlers->current() . ').';
            foreach ($blocked as $agent) {
                $lines[] = 'User-agent: ' . $agent;
            }
            $lines[] = 'Disallow: /';
        }
        $lines[] = '';
        $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';

        return implode("\n", $lines) . "\n";
    }

    private function buildLlms(string $base): string
    {
        $pages = $this->indexable();
        $lines = ['# ' . $this->site->name, '', '> ' . $this->summary($pages), ''];
        $lines[] = 'Every page of this website is also available as Markdown: add `.md` to its address (`/index.md` for the home page).';
        $byLocale = [];
        foreach ($pages as $page) {
            $byLocale[$page->locale][] = $page;
        }
        foreach ($byLocale as $locale => $localePages) {
            $lines[] = '';
            $lines[] = count($byLocale) > 1 ? sprintf('## Pages in %s (%s)', LocaleCode::describe($locale), $locale) : '## Pages';
            $lines[] = '';
            foreach ($localePages as $page) {
                $notes = $page->description ?? $page->summary;
                $lines[] = sprintf('- [%s](%s%s)%s', self::linkText($page->title), $base, $page->markdownUrl(), $notes === null ? '' : ': ' . self::oneLine($notes));
            }
        }
        $lines[] = '';
        $lines[] = '## Optional';
        $lines[] = '';
        $lines[] = sprintf('- [Full text of every page](%s/llms-full.txt): all the pages above in one Markdown file', $base);

        return implode("\n", $lines) . "\n";
    }

    private function buildLlmsFull(string $base): string
    {
        $pages = $this->indexable();
        $text = '# ' . $this->site->name . "\n\n> " . $this->summary($pages) . "\n";
        $left = count($pages);
        foreach ($pages as $page) {
            $document = $this->documents->document($page->locale, $page->title, $page->summary, $page->blocks, $base . $page->url, $page->updatedAt, $base);
            if (strlen($text) + strlen($document) > self::FULL_TEXT_MAX_BYTES) {
                return $text . sprintf(
                    "\n---\n\n> %d more %s left out to keep this file under %d KB. Every page is listed in %s/llms.txt and has a Markdown version.\n",
                    $left,
                    $left === 1 ? 'page was' : 'pages were',
                    intdiv(self::FULL_TEXT_MAX_BYTES, 1000),
                    $base,
                );
            }
            $text .= "\n" . $document;
            --$left;
        }

        return $text;
    }

    /**
     * @param list<IndexedPage> $pages
     */
    private function summary(array $pages): string
    {
        $about = SiteProfile::read($this->settings)->description;
        if ($about !== null) {
            return self::oneLine($about);
        }
        foreach ($pages as $page) {
            if ($page->home && ($page->description ?? $page->summary) !== null) {
                return self::oneLine((string) ($page->description ?? $page->summary));
            }
        }

        return 'The website of ' . $this->site->name . '.';
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function linkText(string $text): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], self::oneLine($text));
    }

    /**
     * @param callable(string): string $build
     */
    private function cached(string $name, string $baseUrl, callable $build): string
    {
        $base = rtrim($baseUrl, '/');
        $item = $this->cache()->getItem($name . '.' . hash('xxh128', $base));
        if ($item->isHit() && is_string($item->get())) {
            return $item->get();
        }
        $contents = $build($base);
        $this->cache()->save($item->set($contents));

        return $contents;
    }

    private function cache(): CacheItemPoolInterface
    {
        return $this->cache ??= new FilesystemAdapter('seo-' . $this->site->id, 0, $this->config->cacheDir);
    }
}
