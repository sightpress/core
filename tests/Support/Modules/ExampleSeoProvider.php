<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use DateTimeImmutable;
use SightPress\Module\Seo\SeoEntry;
use SightPress\Module\Seo\SeoProvider;

/**
 * A module's SEO entries, for registry tests.
 */
final class ExampleSeoProvider implements SeoProvider
{
    public function entries(string $locale): iterable
    {
        yield new SeoEntry('/events', $locale, 'Events', new DateTimeImmutable('2026-11-24T00:00:00Z'));
    }
}
