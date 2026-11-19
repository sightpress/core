<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatched before a public screen that isn't a page (site search's results, for example) is rendered in the
 * theme, so modules can add what every public page shares: the menus, the logo, the site's icons. Unlike
 * {@see PublicPageRendering} it has no canonical address, no social or schema.org tags of a page and no breadcrumb
 * of pages above it: such screens are never listed by search engines.
 */
final class PublicScreenRendering
{
    /** @var array<string, mixed> */
    private array $variables = [];

    /** @var list<string> */
    private array $head = [];

    /**
     * @param string $locale  the language the screen is shown in, one of the site's
     * @param string $url     its address on the site, for example `/search` or `/pt-pt/search`, without the query
     * @param string $baseUrl scheme and host of the site, for example `https://example.org`
     * @param string $title   its title, as in its `<h1>`
     */
    public function __construct(
        public readonly ServerRequestInterface $request,
        public readonly string $locale,
        public readonly string $url,
        public readonly string $baseUrl,
        public readonly string $title,
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
     * Adds HTML to the screen's `<head>`. The listener escapes everything it puts in.
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
