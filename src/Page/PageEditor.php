<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Core\Template\Message;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Block\Contract\BlockValidator;
use SightPress\Core\Block\Contract\InvalidBlocks;
use SightPress\Core\Page\Contract\PageCloned;
use SightPress\Core\Page\Contract\PageCreated;
use SightPress\Core\Page\Contract\PageMoved;
use SightPress\Core\Page\Contract\PagePurged;
use SightPress\Core\Page\Contract\PageSaved;
use SightPress\Core\Page\Contract\RevisionRestored;
use SightPress\Core\Page\Contract\TranslationPublished;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Entity\PageRevision;
use SightPress\Core\Page\Entity\PageTranslation;
use SightPress\Core\Page\Revision\RevisionHistory;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Template\Translations;
use SightPress\Core\Tenant\Site;

/**
 * Creates, writes, copies, moves and deletes pages, keeping the tree and the addresses right. Every save writes a
 * revision, and every change of address keeps the redirects table right ({@see PageRedirects}): the old address of a
 * page visitors could read leads to the new one, and an address a page takes is no longer redirected.
 *
 * Rules it keeps:
 * - a translation exists only in one of the site's locales, and only when the parent has one in the same locale
 *   (so every address has a parent address);
 * - a slug is unique among the children of one parent in one locale; top-level pages can't use a system address
 *   (`admin`, `login` …) or a locale prefix of the site;
 * - pages nest at most {@see Page::MAX_DEPTH} levels and never under themselves (with slugs of at most
 *   {@see Slug::MAX_LENGTH} characters, an address is never longer than 609 characters);
 * - positions among siblings are 0, 1, 2 … with no gaps;
 * - nothing is moved under a page in the trash, and only pages in the trash are deleted for good.
 *
 * Blocks are checked, upgraded and cleaned by the block module ({@see BlockValidator}); blocks that can't be saved
 * throw {@see InvalidBlocks}. Everything is checked before anything is written; the writes then happen in one
 * transaction.
 */
final readonly class PageEditor
{
    public function __construct(
        private PageRepository $pages,
        private Site $site,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
        private BlockValidator $blocks,
        private PageRedirects $redirects,
        private Translations $translations,
    ) {
    }

    /**
     * Adds a page at the end of its parent's children (or of the top level).
     */
    public function create(string $locale, PageContent $content, ?Page $parent = null, ?int $authorId = null): Page
    {
        $this->checkLocale($locale);
        $content = $content->withBlocks($this->blocks->validate($content->blocks)->blocks);
        $path = $this->pathFor($parent, $locale, $content->slug);
        $this->checkAddressIsFree($locale, $path, null);
        $position = count($this->pages->children($parent));
        $now = $this->clock->now();
        $page = new Page($parent, $position, $authorId, $now);
        $page->write($locale, $content, $path, $now);

        $revision = $this->pages->transaction(function () use ($page, $authorId, $locale, $path): PageRevision {
            $this->pages->add($page);
            $this->pages->flush();
            $page->placeInTree();
            $revision = $this->revise($page, $authorId);
            $this->redirects->claim($locale, $path);

            return $revision;
        });

        $id = (int) $page->id();
        $this->events->dispatch(new PageCreated($id, $locale, $authorId));
        $this->events->dispatch(new PageSaved($id, $locale, $revision->number(), $authorId));

        return $page;
    }

    /**
     * Writes the page in one locale (adding the translation if it is new). A new slug moves the addresses of
     * every page below it in that locale. A translation added to a page that is already written in another
     * language starts as a draft of its own ({@see publishTranslation()}).
     */
    public function save(Page $page, string $locale, PageContent $content, ?int $savedBy = null): PageRevision
    {
        return $this->write($page, $locale, $content, $savedBy, $page->translation($locale) === null && $page->translations() !== []);
    }

    /**
     * Starts a translation as a copy of the page in another of its languages (title, summary, description, search
     * fields and every block), as a draft to be translated. The page in the other language doesn't change.
     *
     * @throws PageException when the page is already written in `$locale`, or isn't written in `$from`
     */
    public function translate(Page $page, string $locale, string $from, ?int $savedBy = null): PageRevision
    {
        $this->checkLocale($locale);
        if ($page->translation($locale) !== null) {
            throw new PageException(new Message('The page is already written in "{locale}". Change that translation instead.', ['locale' => $locale]));
        }
        $source = $page->translation($from) ?? throw new PageException(new Message('The page isn\'t written in "{from}", so it can\'t be copied from it.', ['from' => $from]));

        return $this->write($page, $locale, $source->content(), $savedBy, true);
    }

    /**
     * Adds a translation the AI assistant drafted (A-6), as a draft marked as such: it can't be published until a
     * person edits it or says they checked it ({@see confirmTranslation()}).
     *
     * @throws PageException when the page is already written in `$locale`, or the address is taken
     * @throws InvalidBlocks when a block doesn't pass the checks
     */
    public function draftTranslation(Page $page, string $locale, PageContent $content, ?int $savedBy = null): PageRevision
    {
        $this->checkLocale($locale);
        if ($page->translation($locale) !== null) {
            throw new PageException(new Message('The page is already written in "{locale}". Change that translation instead.', ['locale' => $locale]));
        }

        return $this->write($page, $locale, $content, $savedBy, true, true);
    }

    /**
     * A person says they checked a translation the AI assistant drafted: it can be published from now on.
     *
     * @return bool whether anything changed
     *
     * @throws PageException when the page isn't written in `$locale`
     */
    public function confirmTranslation(Page $page, string $locale, ?int $by = null): bool
    {
        $this->checkLocale($locale);
        $translation = $page->translation($locale) ?? throw new PageException(new Message('The page isn\'t written in "{locale}" yet.', ['locale' => $locale]));
        if (!$translation->isMachineDrafted()) {
            return false;
        }
        $revision = $this->pages->transaction(function () use ($page, $translation, $by): PageRevision {
            $translation->setMachineDrafted(false);

            return $this->revise($page, $by);
        });
        $this->events->dispatch(new PageSaved((int) $page->id(), $locale, $revision->number(), $by));

        return true;
    }

    /**
     * Publishes a translation on its own (visitors read it once the page is published), or makes it a draft again.
     *
     * @return bool whether anything changed
     *
     * @throws PageException when the page isn't written in `$locale`
     */
    public function publishTranslation(Page $page, string $locale, bool $published, ?int $by = null): bool
    {
        $this->checkLocale($locale);
        $translation = $page->translation($locale) ?? throw new PageException(new Message('The page isn\'t written in "{locale}" yet.', ['locale' => $locale]));
        if ($translation->isDraft() !== $published) {
            return false;
        }
        $revision = $this->pages->transaction(function () use ($page, $translation, $published, $by): PageRevision {
            $translation->setDraft(!$published, $this->clock->now());

            return $this->revise($page, $by);
        });
        $this->events->dispatch(new TranslationPublished((int) $page->id(), $locale, $published, $revision->number(), $by));

        return true;
    }

    /**
     * Writes the page in one language as an older revision kept it (title, address, summary, search fields and
     * blocks), as a new revision. The page's state doesn't change, and a translation that is a draft stays one.
     *
     * Restoring never publishes, so it is refused while visitors can read the translation: the page has to be taken
     * back to draft (or the translation made a draft) first.
     *
     * @throws PageException when the revision is of another page or doesn't have the translation, or visitors can
     *                       read the translation now
     * @throws InvalidBlocks when a block of the version no longer passes the checks
     */
    public function restore(Page $page, PageRevision $revision, string $locale, ?int $restoredBy = null): PageRevision
    {
        $this->checkLocale($locale);
        $kept = $revision->page() === $page ? RevisionHistory::translation($revision, $locale) : null;
        if ($kept === null) {
            throw new PageException(new Message('Version {number} of the page isn\'t written in "{locale}".', ['number' => $revision->number(), 'locale' => $locale]));
        }
        if ($page->translation($locale)?->isPublished() === true) {
            throw new PageException('Visitors can read this translation now, and restoring never publishes. Take the page back to draft, or make this translation a draft again, then restore the older version.');
        }
        $content = new PageContent(
            is_string($kept['title'] ?? null) ? $kept['title'] : '',
            is_string($kept['slug'] ?? null) ? $kept['slug'] : null,
            is_string($kept['summary'] ?? null) ? $kept['summary'] : null,
            is_string($kept['description'] ?? null) ? $kept['description'] : null,
            is_array($kept['blocks'] ?? null) ? array_values($kept['blocks']) : [],
            is_int($kept['social_image'] ?? null) ? $kept['social_image'] : null,
            is_string($kept['seo_title'] ?? null) ? $kept['seo_title'] : null,
            ($kept['noindex'] ?? false) === true,
            is_string($kept['canonical_url'] ?? null) ? $kept['canonical_url'] : null,
            ($kept['exclude_from_site_search'] ?? false) === true,
        );
        $saved = $this->save($page, $locale, $content, $restoredBy);
        $this->events->dispatch(new RevisionRestored((int) $page->id(), $locale, $revision->number(), $saved->number(), $restoredBy));

        return $saved;
    }

    /**
     * @param bool $machine whether the AI assistant drafted it (A-6); anything else a person writes clears that
     */
    private function write(Page $page, string $locale, PageContent $content, ?int $savedBy, bool $draft, bool $machine = false): PageRevision
    {
        $this->checkLocale($locale);
        $content = $content->withBlocks($this->blocks->validate($content->blocks)->blocks);
        $path = $this->pathFor($page->parent(), $locale, $content->slug);
        $this->checkAddressIsFree($locale, $path, $page);
        $oldPath = $page->translation($locale)?->path();
        $below = $oldPath !== null && $oldPath !== $path ? $this->translationsBelow($page, $locale) : [];
        $before = self::addresses([...$below, ...($page->translation($locale) === null ? [] : [$page->translation($locale)])]);

        $revision = $this->pages->transaction(function () use ($page, $locale, $content, $path, $oldPath, $below, $savedBy, $draft, $before, $machine): PageRevision {
            foreach ($below as $translation) {
                $translation->rebase((string) $oldPath, $path);
            }
            $page->write($locale, $content, $path, $this->clock->now(), $draft)->setMachineDrafted($machine);
            $revision = $this->revise($page, $savedBy);
            if ($oldPath === null) {
                $this->redirects->claim($locale, $path);
            }
            $this->redirect($before);

            return $revision;
        });

        $this->events->dispatch(new PageSaved((int) $page->id(), $locale, $revision->number(), $savedBy));

        return $revision;
    }

    /**
     * Moves the page under a new parent (null for the top level) at a position among its new siblings; with the
     * same parent, it only changes the order. Pages below it move with it.
     */
    public function move(Page $page, ?Page $parent, int $position, ?int $movedBy = null): PageRevision
    {
        if ($parent !== null && ($parent === $page || $page->isAncestorOf($parent))) {
            throw new PageException('A page can\'t be moved under itself or under one of its own subpages.');
        }
        if ($parent?->state() === PageState::Trash) {
            throw new PageException('A page can\'t be moved under a page in the trash.');
        }
        $descendants = $this->pages->descendants($page);
        $height = 0;
        foreach ($descendants as $descendant) {
            $height = max($height, $descendant->depth() - $page->depth());
        }
        $newDepth = $parent === null ? 0 : $parent->depth() + 1;
        if ($newDepth + $height >= Page::MAX_DEPTH) {
            throw new PageException(new Message('Pages can be nested at most {max} levels deep.', ['max' => (string) Page::MAX_DEPTH]));
        }

        $paths = [];
        foreach ($page->translations() as $locale => $translation) {
            $paths[$locale] = $this->pathFor($parent, $locale, $translation->slug());
            $this->checkAddressIsFree($locale, $paths[$locale], $page);
        }

        $oldParent = $page->parent();
        $oldSiblings = array_values(array_filter($this->pages->children($oldParent), static fn (Page $sibling): bool => $sibling !== $page));
        $newSiblings = $oldParent === $parent ? $oldSiblings : $this->pages->children($parent);
        $position = max(0, min($position, count($newSiblings)));
        array_splice($newSiblings, $position, 0, [$page]);
        $before = self::addresses(array_merge(...array_map(static fn (Page $moved): array => array_values($moved->translations()), [$page, ...$descendants])));

        $revision = $this->pages->transaction(function () use ($page, $parent, $position, $paths, $descendants, $oldSiblings, $newSiblings, $movedBy, $before): PageRevision {
            foreach ($page->translations() as $locale => $translation) {
                $oldPath = $translation->path();
                foreach ($descendants as $descendant) {
                    $descendant->translation($locale)?->rebase($oldPath, $paths[$locale]);
                }
                $page->write($locale, $translation->content(), $paths[$locale], $this->clock->now());
            }
            $page->moveTo($parent, $position, $this->clock->now());
            foreach ($descendants as $descendant) {
                $descendant->followParent();
            }
            foreach ([$oldSiblings, $newSiblings] as $siblings) {
                foreach ($siblings as $index => $sibling) {
                    $sibling->setPosition($index);
                }
            }
            $revision = $this->revise($page, $movedBy);
            $this->redirect($before);

            return $revision;
        });

        $this->events->dispatch(new PageMoved(
            (int) $page->id(),
            $oldParent?->id(),
            $parent?->id(),
            $position,
            $revision->number(),
            $movedBy,
        ));

        return $revision;
    }

    /**
     * Moves the page one step: up or down among its siblings, in under the page before it (as its last subpage) or
     * out next to the page it is under.
     *
     * @throws PageException when it can't go that way: it is first, last, has no page before it or is at the top
     */
    public function shift(Page $page, PageShift $shift, ?int $movedBy = null): PageRevision
    {
        $parent = $page->parent();
        $position = $page->position();
        $siblings = $this->pages->children($parent);

        return match ($shift) {
            PageShift::Up => $position === 0
                ? throw new PageException('The page is already the first one at its level.')
                : $this->move($page, $parent, $position - 1, $movedBy),
            PageShift::Down => $position >= count($siblings) - 1
                ? throw new PageException('The page is already the last one at its level.')
                : $this->move($page, $parent, $position + 1, $movedBy),
            PageShift::In => $this->move($page, $siblings[$position - 1] ?? throw new PageException('There is no page before this one to move it under.'), PHP_INT_MAX, $movedBy),
            PageShift::Out => $parent === null
                ? throw new PageException('The page is already at the top level.')
                : $this->move($page, $parent->parent(), $parent->position() + 1, $movedBy),
        };
    }

    /**
     * Copies the page into a new draft page right after it, in every language of the site it is written in: the
     * title becomes "Copy of …" (in that language) and the slug gets `-copy` (`-copy-2` … when that is taken). The
     * layout, visibility, fields and blocks are copied; the pages under it are not.
     */
    public function copy(Page $source, ?int $copiedBy = null): Page
    {
        $parent = $source->parent();
        $contents = [];
        foreach ($this->site->locales as $locale) {
            $translation = $source->translation($locale);
            if ($translation === null) {
                continue;
            }
            $title = $this->translations->translate(new Message('Copy of {title}', ['title' => $translation->title()]), [], $locale);
            $title = mb_substr($title, 0, PageContent::TITLE_MAX_LENGTH);
            $content = $translation->content();
            $slug = $this->freeSlug($parent, $locale, $content->slug);
            $contents[$locale] = [$content->withTitle($title, $slug), $this->pathFor($parent, $locale, $slug), $translation->isDraft()];
        }
        if ($contents === []) {
            throw new PageException('The page isn\'t written in any of the site\'s languages, so there is nothing to copy.');
        }
        $siblings = $this->pages->children($parent);
        $now = $this->clock->now();
        $copy = new Page($parent, $source->position() + 1, $copiedBy, $now);
        $copy->setLayout($source->layout(), $now);
        $copy->setPublic($source->isPublic(), $now);
        foreach ($contents as $locale => [$content, $path, $draft]) {
            $copy->write($locale, $content, $path, $now, $draft);
        }
        array_splice($siblings, $source->position() + 1, 0, [$copy]);

        $this->pages->transaction(function () use ($copy, $siblings, $contents, $copiedBy): void {
            foreach ($siblings as $index => $sibling) {
                $sibling->setPosition($index);
            }
            $this->pages->add($copy);
            $this->pages->flush();
            $copy->placeInTree();
            $this->revise($copy, $copiedBy);
            foreach ($contents as $locale => [, $path]) {
                $this->redirects->claim($locale, $path);
            }
        });

        $id = (int) $copy->id();
        $this->events->dispatch(new PageCreated($id, (string) array_key_first($contents), $copiedBy));
        $this->events->dispatch(new PageCloned($id, (int) $source->id(), $copiedBy));

        return $copy;
    }

    /**
     * Deletes a page in the trash for good, with its revisions and the pages under it (all in the trash too). Its
     * addresses answer "gone" from then on, and the pages after it close up.
     *
     * @throws PageException when the page, or a page under it, isn't in the trash
     */
    public function purge(Page $page, ?int $purgedBy = null): void
    {
        $descendants = $this->pages->descendants($page);
        foreach ([$page, ...$descendants] as $doomed) {
            if ($doomed->state() !== PageState::Trash) {
                throw new PageException('Only pages in the trash can be deleted for good, with every page under them.');
            }
        }
        $translations = $page->translations();
        $first = $translations[$this->site->defaultLocale] ?? reset($translations);
        $title = $first === false ? sprintf('page %d', (int) $page->id()) : $first->title();
        $doomed = [...array_reverse($descendants), $page];
        $ids = array_map(static fn (Page $gone): int => (int) $gone->id(), [$page, ...$descendants]);
        $addresses = [];
        foreach ($doomed as $gone) {
            foreach ($gone->translations() as $locale => $translation) {
                $addresses[] = [(string) $locale, $translation->path()];
            }
        }
        $parent = $page->parent();

        $this->pages->transaction(function () use ($doomed, $parent, $addresses): void {
            $this->pages->delete($doomed);
            foreach ($this->pages->children($parent) as $index => $sibling) {
                $sibling->setPosition($index);
            }
            $this->pages->flush();
            foreach ($addresses as [$locale, $path]) {
                $this->redirects->gone($locale, $path);
            }
        });

        $this->events->dispatch(new PagePurged($ids[0], $title, $ids, $purgedBy));
    }

    /**
     * The slug with `-copy` (then `-copy-2`, `-copy-3` …) that no page uses yet under the parent in the locale.
     */
    private function freeSlug(?Page $parent, string $locale, string $slug): string
    {
        for ($n = 1; ; ++$n) {
            $suffix = $n === 1 ? '-copy' : '-copy-' . $n;
            $candidate = rtrim(substr($slug, 0, Slug::MAX_LENGTH - strlen($suffix)), '-') . $suffix;
            if ($this->pages->findByPath($locale, $this->pathFor($parent, $locale, $candidate)) === null) {
                return $candidate;
            }
        }
    }

    /**
     * Each translation with its address and whether visitors can read it, before a change.
     *
     * @param list<PageTranslation> $translations
     *
     * @return list<array{PageTranslation, string, bool}>
     */
    private static function addresses(array $translations): array
    {
        return array_map(static fn (PageTranslation $translation): array => [$translation, $translation->path(), $translation->isPublished()], $translations);
    }

    /**
     * Records the old addresses of the translations whose address changed.
     *
     * @param list<array{PageTranslation, string, bool}> $before
     */
    private function redirect(array $before): void
    {
        foreach ($before as [$translation, $old, $public]) {
            if ($translation->path() !== $old) {
                $this->redirects->moved($translation->locale(), $old, $translation->path(), $public);
            }
        }
    }

    private function revise(Page $page, ?int $savedBy): PageRevision
    {
        $revision = new PageRevision($page, $this->pages->lastRevisionNumber($page) + 1, $savedBy, $this->clock->now());
        $this->pages->add($revision);
        $this->pages->flush();

        return $revision;
    }

    private function checkLocale(string $locale): void
    {
        if (!in_array($locale, $this->site->locales, true)) {
            throw new PageException(new Message('This site isn\'t written in "{locale}". Its languages are: {locales}.', ['locale' => $locale, 'locales' => implode(', ', $this->site->locales)]));
        }
    }

    private function pathFor(?Page $parent, string $locale, string $slug): string
    {
        if ($parent === null) {
            if (Slug::isReservedAtRoot($slug, $this->site->locales)) {
                throw new PageException(new Message('The address "{slug}" is used by the system. Choose another one for a top-level page.', ['slug' => $slug]));
            }

            return $slug;
        }

        $parentTranslation = $parent->translation($locale);
        if ($parentTranslation === null) {
            throw new PageException(new Message('The page above this one isn\'t written in "{locale}" yet. Translate it first.', ['locale' => $locale]));
        }

        return $parentTranslation->path() . '/' . $slug;
    }

    private function checkAddressIsFree(string $locale, string $path, ?Page $page): void
    {
        $taken = $this->pages->findByPath($locale, $path);
        if ($taken !== null && $taken->page() !== $page) {
            throw new PageException(new Message('The address "/{path}" is already used by the page "{title}". Choose another one.', ['path' => $path, 'title' => $taken->title()]));
        }
    }

    /**
     * @return list<PageTranslation>
     */
    private function translationsBelow(Page $page, string $locale): array
    {
        $translations = [];
        foreach ($this->pages->descendants($page) as $descendant) {
            $translation = $descendant->translation($locale);
            if ($translation !== null) {
                $translations[] = $translation;
            }
        }

        return $translations;
    }
}
