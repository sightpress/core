<?php

declare(strict_types=1);

namespace SightPress\Core\Search;

use Psr\Log\LoggerInterface;
use SightPress\Module\Event\MediaChanged;
use SightPress\Module\Event\MediaDeleted;
use SightPress\Module\Event\PageMoved;
use SightPress\Module\Event\PagePurged;
use SightPress\Module\Event\PageSaved;
use SightPress\Module\Event\PageTransitioned;
use SightPress\Core\Page\Contract\SearchablePages;
use SightPress\Module\Event\TranslationPublished;
use SightPress\Module\Event\LocaleRemoved;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Module\Event\SiteModuleChanged;
use SightPress\Core\Tenant\Site;
use Throwable;

/**
 * Keeps the index in step with the site, after each change was stored (events are dispatched after the
 * transaction): a page published, unpublished, archived, put in the trash, saved, moved or deleted for good (with the
 * pages below it, whose addresses and breadcrumbs follow it); a media file whose texts changed (the pages that show
 * it). Changes to the whole site mark the index out of date for {@see SearchReindexJob}: its languages, who can read
 * it, search turned off or on. A failure is logged and marks the index out of date; it never stops the change itself.
 */
final readonly class SearchIndexer
{
    /** Site settings whose change touches every document. */
    private const array SITE_WIDE = [SiteVisibility::SETTING, SiteProfile::LOCALES, SiteProfile::DEFAULT_LOCALE];

    public function __construct(
        private SearchIndex $index,
        private SearchablePages $pages,
        private Site $site,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(object $event): void
    {
        try {
            $this->handle($event);
        } catch (Throwable $exception) {
            $this->logger->error('Site search could not be brought up to date; it will be rebuilt by schedule:run or site:search:reindex.', [
                'site' => $this->site->slug,
                'event' => $event::class,
                'error' => $exception->getMessage(),
            ]);
            $this->index->markOutOfDate();
        }
    }

    private function handle(object $event): void
    {
        match (true) {
            $event instanceof PageTransitioned, $event instanceof PageMoved => $this->pages($this->pages->withPagesBelow($event->pageId)),
            $event instanceof PageSaved, $event instanceof TranslationPublished => $this->pages($this->pages->withPagesBelow($event->pageId), $event->locale),
            $event instanceof PagePurged => $this->index->remove(SearchablePages::SOURCE, self::refs($event->pageIds)),
            $event instanceof MediaChanged, $event instanceof MediaDeleted => $this->pages($this->pages->usingMedia($event->mediaId)),
            $event instanceof SettingsChanged => array_intersect($event->keys, self::SITE_WIDE) === [] ? null : $this->index->markOutOfDate(),
            $event instanceof LocaleRemoved => $this->index->forget($event->locale),
            $event instanceof SiteModuleChanged => $event->moduleId === SearchModule::ID ? $this->index->markOutOfDate() : null,
            default => null,
        };
    }

    /**
     * @param list<int>             $ids
     * @param non-empty-string|null $locale
     */
    private function pages(array $ids, ?string $locale = null): void
    {
        $this->index->refresh(SearchablePages::SOURCE, self::refs($ids), $locale);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<non-empty-string>
     */
    private static function refs(array $ids): array
    {
        return array_values(array_map(static fn (int $id): string => (string) $id, array_filter($ids, static fn (int $id): bool => $id > 0)));
    }
}
