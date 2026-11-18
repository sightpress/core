<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use SightPress\Core\Template\Message;
use Locale;
use SightPress\Module\Block\A11ySeverity;

/**
 * Something the publish checks found in one language of a page. Errors stop the page being published; warnings
 * don't.
 */
final readonly class PublishIssue
{
    public const string ACCESSIBILITY = 'accessibility';
    public const string SEO = 'seo';

    /**
     * @param string       $kind    {@see ACCESSIBILITY} or {@see SEO}
     * @param string       $rule    id of the check, for example `image.alt_missing` or `seo.description_missing`
     * @param string|Message $message plain en-GB sentence that says how to fix it (shown translated)
     * @param string       $pointer JSON Pointer to what it is about in the page's content in that language:
     *                              `/title`, `/summary`, `/description`, `/blocks/2` or `/blocks/2/data/alt`
     * @param list<string> $wcag    WCAG success criteria it is about
     */
    public function __construct(
        public string $locale,
        public A11ySeverity $severity,
        public string $kind,
        public string $rule,
        public string|Message $message,
        public string $pointer,
        public array $wcag = [],
    ) {
    }

    public function isError(): bool
    {
        return $this->severity === A11ySeverity::Error;
    }

    /**
     * The name of the issue's language, in another language: "English (United Kingdom)".
     */
    public function languageName(string $inLocale = 'en-GB'): string
    {
        $name = Locale::getDisplayName($this->locale, $inLocale);

        return is_string($name) && $name !== '' ? $name : $this->locale;
    }

    /**
     * The `id` of the field or block in the page editor, so a list of issues can link to it:
     * `en-gb-blocks-2-data-alt`, `pt-br-description`.
     */
    public function anchor(): string
    {
        return self::anchorFor($this->locale, $this->pointer);
    }

    public static function anchorFor(string $locale, string $pointer): string
    {
        $parts = array_map(
            static fn (string $part): string => (string) preg_replace('/[^a-z0-9]+/', '-', strtolower(str_replace(['~1', '~0'], ['/', '~'], $part))),
            array_values(array_filter(explode('/', $pointer), static fn (string $part): bool => $part !== '')),
        );

        return trim(strtolower($locale) . '-' . implode('-', $parts), '-');
    }
}
