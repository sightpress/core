<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Core\Template\Message;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Block\Contract\BlockValidator;
use SightPress\Core\Block\Contract\InvalidBlocks;
use SightPress\Core\Page\Contract\PageCreated;
use SightPress\Core\Page\Contract\PageMoved;
use SightPress\Core\Page\Contract\PageSaved;
use SightPress\Core\Page\Contract\TranslationPublished;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Entity\PageRevision;
use SightPress\Core\Page\Entity\PageTranslation;
use SightPress\Core\Tenant\Site;

/**
 * Creates, writes and moves pages, keeping the tree and the addresses right. Every save writes a revision.
 *
 * Rules it keeps:
 * - a translation exists only in one of the site's locales, and only when the parent has one in the same locale
 *   (so every address has a parent address);
 * - a slug is unique among the children of one parent in one locale; top-level pages can't use a system address
 *   (`admin`, `login` …) or a locale prefix of the site;
 * - pages nest at most {@see Page::MAX_DEPTH} levels and never under themselves (with slugs of at most
 *   {@see Slug::MAX_LENGTH} characters, an address is never longer than 609 characters);
 * - positions among siblings are 0, 1, 2 … with no gaps.
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

        $revision = $this->pages->transaction(function () use ($page, $authorId): PageRevision {
            $this->pages->add($page);
            $this->pages->flush();
            $page->placeInTree();

            return $this->revise($page, $authorId);
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

    private function write(Page $page, string $locale, PageContent $content, ?int $savedBy, bool $draft): PageRevision
    {
        $this->checkLocale($locale);
        $content = $content->withBlocks($this->blocks->validate($content->blocks)->blocks);
        $path = $this->pathFor($page->parent(), $locale, $content->slug);
        $this->checkAddressIsFree($locale, $path, $page);
        $oldPath = $page->translation($locale)?->path();
        $below = $oldPath !== null && $oldPath !== $path ? $this->translationsBelow($page, $locale) : [];

        $revision = $this->pages->transaction(function () use ($page, $locale, $content, $path, $oldPath, $below, $savedBy, $draft): PageRevision {
            foreach ($below as $translation) {
                $translation->rebase((string) $oldPath, $path);
            }
            $page->write($locale, $content, $path, $this->clock->now(), $draft);

            return $this->revise($page, $savedBy);
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

        $revision = $this->pages->transaction(function () use ($page, $parent, $position, $paths, $descendants, $oldSiblings, $newSiblings, $movedBy): PageRevision {
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

            return $this->revise($page, $movedBy);
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
