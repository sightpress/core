<?php

declare(strict_types=1);

namespace SightPress\Core\Block\RichText;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans rich text with an allow-list before it is stored (ADR-0005): paragraphs, line breaks, emphasis, links,
 * lists, headings h2–h6, quotes, code, abbreviations, `lang` / `dir` on any of them (WCAG 3.1.2) and hex text
 * colours ({@see StyleSanitizer}). Scripts, event handlers, iframes, forms, images (use an image block) and any
 * other element or attribute are removed; layout wrappers such as `div` and an `h1` (the page title is the only one)
 * are taken away but their text is kept. Links may use `https`, `http`, `mailto`, `tel` or a path on the site.
 */
final readonly class RichTextSanitizer
{
    public const int MAX_LENGTH = 100_000;

    private const array ELEMENTS = [
        'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 's' => [], 'sub' => [], 'sup' => [],
        'mark' => ['style'], 'span' => ['style'], 'small' => [], 'q' => ['cite'], 'cite' => [], 'abbr' => ['title'],
        'code' => [], 'pre' => [], 'kbd' => [], 'blockquote' => ['cite'], 'hr' => [],
        'ul' => [], 'ol' => ['start', 'reversed'], 'li' => [], 'dl' => [], 'dt' => [], 'dd' => [],
        'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'a' => ['href', 'hreflang'],
    ];

    /** Not allowed, but their text is kept (pasted from other editors). */
    private const array UNWRAPPED = [
        'h1', 'div', 'section', 'article', 'header', 'footer', 'aside', 'main', 'figure', 'figcaption', 'u', 'font',
        'center', 'address', 'big', 'tt', 'label',
    ];

    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = new HtmlSanitizerConfig()
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->withAttributeSanitizer(new StyleSanitizer())
            ->withMaxInputLength(self::MAX_LENGTH);
        foreach (self::ELEMENTS as $element => $attributes) {
            $config = $config->allowElement($element, [...$attributes, 'lang', 'dir']);
        }
        foreach (self::UNWRAPPED as $element) {
            $config = $config->blockElement($element);
        }
        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return trim($this->sanitizer->sanitize($html));
    }
}
