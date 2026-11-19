<?php

declare(strict_types=1);

namespace SightPress\Core\Search;

use SightPress\Core\Infrastructure\Search\SearchExtensions;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Search\SearchBackend;

/**
 * The site's search index: keeps it in step with the documents of every provider (pages first) in the site's
 * languages, in core's back-end or the one a module put in its place.
 *
 * When a change touches the whole site (its languages, who can read it, search turned on again), the index is
 * marked out of date and `schedule:run` (or `site:search:reindex`) builds it again ({@see SearchReindexJob}).
 */
final readonly class SearchIndex
{
    /** Site setting: {@see READY} once the whole index was built, anything else while it needs to be. */
    public const string STATE_SETTING = 'search.index_state';

    public const string READY = 'ready';

    public const string OUT_OF_DATE = 'out_of_date';

    public function __construct(
        private SearchExtensions $extensions,
        private DatabaseSearchBackend $database,
        private Site $site,
        private SiteSettings $settings,
    ) {
    }

    public function backend(): SearchBackend
    {
        return $this->extensions->backend() ?? $this->database;
    }

    /**
     * Indexes the documents again, or removes those that are gone, unpublished or left out of search.
     *
     * @param non-empty-string       $source
     * @param list<non-empty-string> $refs
     * @param non-empty-string|null  $locale one locale, or every language of the site
     */
    public function refresh(string $source, array $refs, ?string $locale = null): void
    {
        $provider = $this->extensions->provider($source);
        if ($provider === null || $refs === []) {
            return;
        }
        $backend = $this->backend();
        foreach ($refs as $ref) {
            foreach ($locale === null ? $this->site->locales : [$locale] as $in) {
                $document = $provider->document($ref, $in);
                if ($document === null) {
                    $backend->remove($source, $ref, $in);
                } else {
                    $backend->index($document);
                }
            }
        }
    }

    /**
     * Removes documents in every locale.
     *
     * @param non-empty-string       $source
     * @param list<non-empty-string> $refs
     */
    public function remove(string $source, array $refs): void
    {
        $backend = $this->backend();
        foreach ($refs as $ref) {
            $backend->remove($source, $ref);
        }
    }

    /**
     * Builds the index again from every provider: in one locale, or in all of them (which also drops languages the
     * site no longer has and marks the index ready). Running it twice gives the same index.
     *
     * @param non-empty-string|null $locale
     *
     * @return array<non-empty-string, int> documents indexed, by locale
     */
    public function reindex(?string $locale = null): array
    {
        $backend = $this->backend();
        $backend->clear($locale);
        $counts = [];
        foreach ($locale === null ? $this->site->locales : [$locale] as $in) {
            $counts[$in] = 0;
            foreach ($this->extensions->providers() as $provider) {
                foreach ($provider->documents($in) as $document) {
                    $backend->index($document);
                    ++$counts[$in];
                }
            }
        }
        if ($locale === null) {
            $this->settings->set(self::STATE_SETTING, self::READY);
        }

        return $counts;
    }

    /**
     * Removes every document in a language the site no longer has.
     *
     * @param non-empty-string $locale
     */
    public function forget(string $locale): void
    {
        $this->backend()->clear($locale);
    }

    public function markOutOfDate(): void
    {
        $this->settings->set(self::STATE_SETTING, self::OUT_OF_DATE);
    }

    public function isReady(): bool
    {
        return $this->settings->get(self::STATE_SETTING) === self::READY;
    }
}
