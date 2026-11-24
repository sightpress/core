<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use DateTimeImmutable;
use RuntimeException;
use SightPress\Module\Seo\SeoEntry;
use SightPress\Module\Seo\SeoProvider;

/**
 * A module's public pages in two languages, for the sitemap and `llms.txt` tests: a tour translated into Portuguese,
 * a talk only in English without a Markdown version, and an entry in a language the site doesn't have. With
 * {@see $fail} it fails instead.
 */
final class ToursSeoProvider implements SeoProvider
{
    public static bool $fail = false;

    public function entries(string $locale): iterable
    {
        if (self::$fail) {
            throw new RuntimeException('The tours table is missing.');
        }
        $at = new DateTimeImmutable('2026-12-01T09:30:00Z');
        if ($locale === 'en-GB') {
            yield new SeoEntry('/tours/river', 'en-GB', 'River [walk]', $at, "A walk\n along the river.", '/tours/river.md', ['pt-PT' => '/pt-pt/visitas/rio', 'fr-FR' => '/fr/tours/riviere']);
            yield new SeoEntry('/talks/maps', 'en-GB', 'Old maps', $at->modify('-1 day'));
            yield new SeoEntry('/fr/tours', 'fr-FR', 'Visites', $at);
        }
        if ($locale === 'pt-PT') {
            yield new SeoEntry('/pt-pt/visitas/rio', 'pt-PT', 'Passeio do rio', new DateTimeImmutable('2026-12-02T00:00:00Z'), null, '/pt-pt/visitas/rio.md', ['en-GB' => '/tours/river']);
        }
    }
}
