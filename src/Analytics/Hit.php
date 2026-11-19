<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics;

use DateTimeImmutable;

/**
 * One raw row of `analytics_hit`: something counted today (ADR-0012). Rows are kept only until their day is rolled
 * up ({@see AnalyticsRollup}). Nothing in it identifies a person: the visitor key changes every day and can't be
 * reversed once the day's salt is gone.
 */
final readonly class Hit
{
    /** A person read a page (HTML or Markdown). */
    public const string VIEW = 'view';

    /** A person asked for an address that doesn't exist. */
    public const string NOT_FOUND = 'not_found';

    /** A bot read a page or a file such as the sitemap. */
    public const string BOT = 'bot';

    /** A person searched the site. */
    public const string SEARCH = 'search';

    /** Longest path kept for "not found", in characters. */
    public const int PATH_LENGTH = 300;

    /**
     * @param string $day the day in the site's time zone, `Y-m-d`
     */
    public function __construct(
        public string $day,
        public DateTimeImmutable $at,
        public string $kind,
        public ?string $resource = null,
        public ?int $pageId = null,
        public ?string $locale = null,
        public ?string $path = null,
        public ?string $visitor = null,
        public ?string $source = null,
        public ?string $device = null,
        public ?string $bot = null,
        public ?string $botKind = null,
        public ?string $query = null,
        public ?int $results = null,
    ) {
    }
}
