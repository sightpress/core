<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Block\BlockType;
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

    /**
     * @param list<BlockType> $blockTypes block types next to core's
     */
    public function __construct(?CoreSiteDatabase $database = null, private readonly array $blockTypes = [])
    {
        $this->database = $database ?? new CoreSiteDatabase();
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

    /**
     * Creates a page and publishes it (the workflow does this in the app).
     *
     * @param list<mixed> $blocks
     */
    public function published(string $title, array $blocks = [], ?Page $parent = null, string $locale = 'en-GB'): Page
    {
        $page = $this->editor()->create($locale, new PageContent($title, null, null, null, $blocks), $parent);
        $page->changeState(PageState::Published, $this->clock->now());
        $this->repository()->flush();

        return $page;
    }

    public function addresses(): PageAddresses
    {
        return new PageAddresses($this->site, $this->repository(), $this->database->settings());
    }

    public function editor(): PageEditor
    {
        return new PageEditor($this->repository(), $this->site, $this->clock, $this, new BlockFixture($this->blockTypes, $this->database)->validator());
    }
}
