<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatched while a page of the public site is rendered, so other modules can add to it: menus and breadcrumbs,
 * `<head>` tags and so on. Listeners add template variables with {@see set()} (the theme's master template reads
 * `main_menu`, `footer_menu` and `breadcrumbs`) and HTML for the `<head>` with {@see addHead()}. They must not
 * query the database once per item: everything a listener needs for a page should take a query or two.
 */
final class PublicPageRendering
{
    /** @var array<string, mixed> */
    private array $variables = [];

    /** @var list<string> */
    private array $head = [];

    private ?string $metaDescription;

    private string $canonicalUrl;

    private ?string $robots = null;

    /**
     * @param string    $url         the page's address on the site, for example `/about/team` or `/` (home)
     * @param string    $baseUrl     scheme and host of the site, for example `https://example.org`
     * @param list<int> $ancestorIds   the pages above this one, from the top of the tree down
     * @param ?int      $socialImageId media id of the page's own share image, if it has one
     * @param ?string   $seoTitle      the title for search engines, when it differs from the page title
     * @param bool      $noindex       whether search engines are asked to leave the page out
     * @param ?string   $canonicalOverride the original's address, when the page copies another one
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     * @param string    $contentHtml   the page's blocks rendered as HTML
     */
    public function __construct(
        public readonly ServerRequestInterface $request,
        public readonly int $pageId,
        public readonly string $locale,
        public readonly string $url,
        public readonly string $baseUrl,
        public readonly string $title,
        public readonly ?string $summary,
        public readonly ?string $description,
        public readonly array $ancestorIds,
        public readonly bool $home,
        public readonly bool $public,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ?int $socialImageId = null,
        public readonly ?string $seoTitle = null,
        public readonly bool $noindex = false,
        public readonly ?string $canonicalOverride = null,
        public readonly array $blocks = [],
        public readonly string $contentHtml = '',
    ) {
        $this->metaDescription = $description ?? $summary;
        $this->canonicalUrl = $canonicalOverride ?? rtrim($baseUrl, '/') . $url;
    }

    /**
     * The description search engines and social networks get: the page's, else its summary, unless a module (SEO)
     * gives a better one.
     */
    public function metaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $description): void
    {
        $this->metaDescription = $description;
    }

    /**
     * The page's one address for search engines and social networks (absolute).
     */
    public function canonicalUrl(): string
    {
        return $this->canonicalUrl;
    }

    public function setCanonicalUrl(string $url): void
    {
        $this->canonicalUrl = $url;
    }

    /**
     * What search engines are asked to do with the page (`noindex, nofollow` …), sent as `X-Robots-Tag` and in a
     * `<meta name="robots">`; null asks nothing.
     */
    public function robots(): ?string
    {
        return $this->robots;
    }

    public function setRobots(?string $robots): void
    {
        $this->robots = $robots;
    }

    public function set(string $name, mixed $value): void
    {
        $this->variables[$name] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    /**
     * Adds HTML to the page's `<head>`. The listener escapes everything it puts in.
     */
    public function addHead(string $html): void
    {
        $this->head[] = $html;
    }

    public function head(): string
    {
        return implode("\n", $this->head);
    }
}
