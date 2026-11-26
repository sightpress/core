<?php

declare(strict_types=1);

namespace SightPress\Core\Content;

use DOMDocument;
use DOMElement;
use enshrined\svgSanitize\Sanitizer;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Block\BlockIcon;

/**
 * The icons that modules which are on registered for blocks with icons, such as `social_links` (ADR-0064): found by
 * name or by a link's host, subdomains included. Each SVG is read once and goes through the same sanitiser as
 * uploads; it is drawn with `currentColor`, hidden from screen readers and never focusable, because the link's text
 * always names it. An icon whose file is missing or has nothing safe left to show is left out, so a module's broken
 * file never breaks a page.
 */
final class BlockIconCatalogue
{
    public const int MAX_BYTES = 64 * 1024;

    /** @var array<string, BlockIcon> */
    private readonly array $icons;

    /** @var array<string, string|null> name => the clean SVG, or null when it can't be used */
    private array $svg = [];

    /**
     * @param list<BlockIcon> $icons
     */
    public function __construct(array $icons = [])
    {
        $byName = [];
        foreach ($icons as $icon) {
            $byName[$icon->name] = $icon;
        }
        ksort($byName);
        $this->icons = $byName;
    }

    public static function fromRegistry(ModuleRegistry $registry): self
    {
        return new self(array_map(static fn ($registered): BlockIcon => $registered->item, $registry->blockIcons()));
    }

    /**
     * @return array<string, BlockIcon> by name, sorted
     */
    public function all(): array
    {
        return $this->icons;
    }

    public function get(string $name): ?BlockIcon
    {
        return $this->icons[$name] ?? null;
    }

    /**
     * The icon for a link: the one whose host is the link's host or a parent of it, the longest match first, so
     * `social.example.org` wins over `example.org`. Null for links no icon stands for.
     */
    public function forUrl(string $url): ?BlockIcon
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower(rtrim($host, '.'));
        $found = null;
        $length = 0;
        foreach ($this->icons as $icon) {
            foreach ($icon->hosts as $pattern) {
                if (($host === $pattern || str_ends_with($host, '.' . $pattern)) && strlen($pattern) > $length) {
                    $found = $icon;
                    $length = strlen($pattern);
                }
            }
        }

        return $found;
    }

    /**
     * The icon as inline SVG, safe to put in a page, or null when there is no such icon or its file can't be used.
     */
    public function svg(string $name): ?string
    {
        if (!array_key_exists($name, $this->svg)) {
            $icon = $this->icons[$name] ?? null;
            $this->svg[$name] = $icon === null ? null : self::clean($icon->svgFile);
        }

        return $this->svg[$name];
    }

    private static function clean(string $file): ?string
    {
        $bytes = is_file($file) && filesize($file) <= self::MAX_BYTES ? file_get_contents($file) : false;
        if (!is_string($bytes)) {
            return null;
        }
        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->minify(true);
        $sanitizer->removeXMLTag(true);
        $clean = $sanitizer->sanitize($bytes);
        $document = new DOMDocument();
        if (!is_string($clean) || $clean === '' || !@$document->loadXML($clean, LIBXML_NONET)) {
            return null;
        }
        $root = $document->documentElement;
        if (!$root instanceof DOMElement || $root->localName !== 'svg' || $root->childElementCount === 0) {
            return null;
        }
        foreach (['width', 'height', 'class', 'role', 'aria-label', 'aria-labelledby'] as $attribute) {
            $root->removeAttribute($attribute);
        }
        $root->setAttribute('aria-hidden', 'true');
        $root->setAttribute('focusable', 'false');
        if (!$root->hasAttribute('fill')) {
            $root->setAttribute('fill', 'currentColor');
        }
        foreach (iterator_to_array($root->getElementsByTagName('title')) as $title) {
            $title->parentNode?->removeChild($title);
        }
        $svg = $document->saveXML($root);

        return is_string($svg) ? $svg : null;
    }
}
