<?php

declare(strict_types=1);

namespace SightPress\Core\Search\Contract;

/**
 * Where site search's results page is, for modules that point to it (the `SearchAction` of the site's `WebSite`
 * node, for example): `/search?q=…` in the site's main language and `/pt-pt/search?q=…` in the others, while the
 * module {@see MODULE} is on.
 */
final class SearchPage
{
    public const string MODULE = 'search';

    public const string PATH = '/search';

    /** Name of the query parameter that holds what was searched for. */
    public const string QUERY = 'q';

    /** Name of the query parameter with the page of results, from 1. */
    public const string PAGE = 'page';

    /**
     * The results page's address in a language: `/search`, `/pt-pt/search`.
     */
    public static function url(string $locale, string $defaultLocale): string
    {
        return ($locale === $defaultLocale ? '' : '/' . strtolower($locale)) . self::PATH;
    }
}
