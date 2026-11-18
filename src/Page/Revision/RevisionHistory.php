<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Revision;

use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Entity\PageRevision;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;

/**
 * The versions of a page in one language: of every revision of the page, the ones in which that translation was
 * new or changed (saving another language, or moving a page above, doesn't make a version of this one), each with
 * the revision it is compared with.
 */
final readonly class RevisionHistory
{
    public function __construct(private PageRepository $pages)
    {
    }

    /**
     * @return list<RevisionVersion> newest first
     */
    public function versions(Page $page, string $locale): array
    {
        $versions = [];
        $previous = null;
        foreach (array_reverse($this->pages->revisions($page)) as $revision) {
            $translation = self::translation($revision, $locale);
            if ($translation === null) {
                continue;
            }
            if ($previous === null || $translation !== self::translation($previous, $locale)) {
                $versions[] = new RevisionVersion($revision, self::state($revision), $previous);
            }
            $previous = $revision;
        }

        return array_reverse($versions);
    }

    /**
     * The revision with its number, with the one before it that has the translation (to compare it with).
     */
    public function version(Page $page, string $locale, int $number): ?RevisionVersion
    {
        $found = null;
        $previous = null;
        foreach (array_reverse($this->pages->revisions($page)) as $revision) {
            if ($revision->number() === $number) {
                $found = $revision;
                break;
            }
            if (self::translation($revision, $locale) !== null) {
                $previous = $revision;
            }
        }

        return $found === null ? null : new RevisionVersion($found, self::state($found), $previous);
    }

    /**
     * The translation as the revision kept it.
     *
     * @return array<array-key, mixed>|null
     */
    public static function translation(PageRevision $revision, string $locale): ?array
    {
        $translations = $revision->snapshot()['translations'] ?? null;
        $translation = is_array($translations) ? ($translations[$locale] ?? null) : null;

        return is_array($translation) ? $translation : null;
    }

    private static function state(PageRevision $revision): ?PageState
    {
        $state = $revision->snapshot()['state'] ?? null;

        return is_string($state) ? PageState::tryFrom($state) : null;
    }
}
