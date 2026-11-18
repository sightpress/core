<?php

declare(strict_types=1);

namespace SightPress\Core\Seo;

use SightPress\Core\Block\Contract\BlockContexts;
use SightPress\Core\Block\Contract\BlockRenderer;
use SightPress\Core\Page\Contract\MarkdownAddress;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Tenant\Site;

/**
 * What search engines read in the `<head>` of every public page: the `<title>` ({@see SeoMeta::title()}), the meta
 * description ({@see SeoMeta::description()}; the social tags use it too), the canonical address, the Markdown
 * version (for pages search engines may index), `hreflang` alternates when the site has more than one language (the
 * same set as the sitemap, with `x-default`, the Markdown version of each and `og:locale:alternate`), the robots rules ({@see RobotsPolicy}, also sent as
 * `X-Robots-Tag`), and the page's schema.org nodes: a `WebPage` that is part of the site's `WebSite`, its
 * `BreadcrumbList`, and the nodes the blocks give (videos, for example). Runs before the branding module's tags.
 */
final readonly class SeoHead
{
    public const int PRIORITY = 10;

    public function __construct(
        private Site $site,
        private RobotsPolicy $robots,
        private PageLinks $pages,
        private BlockRenderer $renderer,
        private BlockContexts $contexts,
        private SearchConsoles $consoles,
    ) {
    }

    public function __invoke(PublicPageRendering $event): void
    {
        $base = rtrim($event->baseUrl, '/');
        $description = SeoMeta::description($event->description, $event->summary, $event->contentHtml);
        $event->setMetaDescription($description);
        $title = SeoMeta::title($event->seoTitle, $event->title, $this->site->name);
        $event->set('document_title', $title);
        $robots = $this->robots->forPage($event->public, $event->noindex);
        $event->setRobots($robots);
        $canonical = $event->canonicalUrl();

        $tags = [];
        if ($description !== null) {
            $tags[] = self::tag('meta', ['name' => 'description', 'content' => $description]);
        }
        if ($robots !== null) {
            $tags[] = self::tag('meta', ['name' => 'robots', 'content' => $robots]);
        }
        $tags[] = self::tag('link', ['rel' => 'canonical', 'href' => $canonical]);
        if ($event->home) {
            foreach ($this->consoles->tags() as $name => $code) {
                $tags[] = self::tag('meta', ['name' => $name, 'content' => $code]);
            }
        }
        $indexable = $event->public && !$event->noindex && $event->canonicalOverride === null;
        if ($indexable) {
            $tags[] = self::tag('link', ['rel' => 'alternate', 'type' => 'text/markdown', 'href' => $base . MarkdownAddress::of($event->url, $event->home)]);
        }
        // The same set of languages as the sitemap: only real translations search engines may list, each pointing at
        // all the others and at itself, with x-default for the default language.
        $alternates = [];
        if ($indexable && count($this->site->locales) > 1) {
            foreach ($this->site->locales as $locale) {
                $link = $locale === $event->locale ? null : ($this->pages->find([$event->pageId], $locale)[$event->pageId] ?? null);
                if ($locale === $event->locale) {
                    $alternates[$locale] = $event->url;
                } elseif ($link !== null && $link->indexable) {
                    $alternates[$locale] = $link->url;
                }
            }
        }
        if (count($alternates) > 1) {
            foreach ($alternates as $locale => $url) {
                $tags[] = self::tag('link', ['rel' => 'alternate', 'hreflang' => $locale, 'href' => $base . $url]);
            }
            if (isset($alternates[$this->site->defaultLocale])) {
                $tags[] = self::tag('link', ['rel' => 'alternate', 'hreflang' => 'x-default', 'href' => $base . $alternates[$this->site->defaultLocale]]);
            }
            foreach ($alternates as $locale => $url) {
                if ($locale !== $event->locale) {
                    $tags[] = self::tag('link', ['rel' => 'alternate', 'type' => 'text/markdown', 'hreflang' => $locale, 'href' => $base . MarkdownAddress::of($url, $event->home)]);
                    $tags[] = self::tag('meta', ['property' => 'og:locale:alternate', 'content' => str_replace('-', '_', $locale)]);
                }
            }
        }
        $tags[] = '<script type="application/ld+json">' . json_encode(
            ['@context' => 'https://schema.org', '@graph' => $this->graph($event, $base, $canonical, $title, $description)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
        ) . '</script>';
        $event->addHead(implode("\n", $tags));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function graph(PublicPageRendering $event, string $base, string $canonical, string $title, ?string $description): array
    {
        $page = array_filter([
            '@type' => 'WebPage',
            '@id' => $canonical . '#webpage',
            'url' => $canonical,
            'name' => $title,
            'description' => $description,
            'inLanguage' => $event->locale,
            'isPartOf' => ['@id' => $base . '/#website'],
            'dateModified' => $event->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'breadcrumb' => $event->home ? null : ['@id' => $canonical . '#breadcrumb'],
        ], static fn (mixed $value): bool => $value !== null);
        $graph = [$page];
        if (!$event->home) {
            $crumbs = [['name' => 'Home', 'item' => $base . ($event->locale === $this->site->defaultLocale ? '/' : '/' . strtolower($event->locale))]];
            $links = $this->pages->find($event->ancestorIds, $event->locale);
            foreach ($event->ancestorIds as $id) {
                if (isset($links[$id]) && $links[$id]->public) {
                    $crumbs[] = ['name' => $links[$id]->title, 'item' => $base . $links[$id]->url];
                }
            }
            $crumbs[] = ['name' => $event->title, 'item' => $base . $event->url];
            $graph[] = [
                '@type' => 'BreadcrumbList',
                '@id' => $canonical . '#breadcrumb',
                'itemListElement' => array_map(
                    static fn (int $index, array $crumb): array => ['@type' => 'ListItem', 'position' => $index + 1, ...$crumb],
                    array_keys($crumbs),
                    $crumbs,
                ),
            ];
        }
        $context = $this->contexts->create($event->locale, $base);
        foreach ($this->renderer->structuredData($event->blocks, $context) as $node) {
            $graph[] = $node;
        }

        return $graph;
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function tag(string $name, array $attributes): string
    {
        $html = '<' . $name;
        foreach ($attributes as $attribute => $value) {
            $html .= sprintf(' %s="%s"', $attribute, htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
        }

        return $html . '>';
    }
}
