<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tenant\SiteStorage;

/**
 * Pages for tests: a migrated site database in memory, a site written in en-GB and pt-PT, a fixed clock and a
 * dispatcher that keeps the events.
 */
final class PageFixture implements EventDispatcherInterface
{
    public readonly CoreSiteDatabase $database;

    public readonly FixedClock $clock;

    public readonly Site $site;

    /** @var list<object> */
    public array $events = [];

    public function __construct()
    {
        $this->database = new CoreSiteDatabase();
        $this->clock = new FixedClock();
        $this->site = self::site(['en-GB', 'pt-PT']);
    }

    /**
     * @param list<string> $locales the first one is the default
     */
    public static function site(array $locales): Site
    {
        return new Site(
            id: SiteFixture::id('demo'),
            slug: 'demo',
            name: 'Demo',
            status: SiteStatus::Enabled,
            ownerType: OwnerType::Organization,
            domains: ['demo.example'],
            databaseDsn: SiteFixture::secretBox()->encrypt('sqlite:///:memory:'),
            storage: new SiteStorage(),
            defaultLocale: $locales[0],
            locales: $locales,
            defaultTimezone: 'Europe/London',
            createdAt: new DateTimeImmutable('2026-11-14T09:30:00Z'),
        );
    }

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    public function repository(): PageRepository
    {
        return new PageRepository($this->database->entityManager);
    }

    public function editor(): PageEditor
    {
        return new PageEditor($this->repository(), $this->site, $this->clock, $this);
    }
}
