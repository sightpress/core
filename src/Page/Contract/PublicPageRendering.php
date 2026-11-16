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

    /**
     * @param string    $url         the page's address on the site, for example `/about/team` or `/` (home)
     * @param string    $baseUrl     scheme and host of the site, for example `https://example.org`
     * @param list<int> $ancestorIds   the pages above this one, from the top of the tree down
     * @param ?int      $socialImageId media id of the page's own share image, if it has one
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
    ) {
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
