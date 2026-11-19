<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Core\Block\Contract\InvalidBlocks;
use SightPress\Core\Page\Contract\TranslationDrafts;
use SightPress\Core\Page\Contract\TranslationSource;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;

/**
 * Reads a page to translate and saves the AI assistant's translation through the page editor, as a draft marked as
 * the assistant's ({@see PageEditor::draftTranslation()}).
 */
final readonly class TranslationDraftService implements TranslationDrafts
{
    public function __construct(
        private PageRepository $pages,
        private PageEditor $editor,
        private Authorizer $authorizer,
        private Site $site,
    ) {
    }

    public function source(Actor $actor, int $pageId, string $from, string $to): ?TranslationSource
    {
        $page = $this->editable($actor, $pageId, $to);
        $source = $page?->translation($from);
        if ($source === null) {
            return null;
        }

        return new TranslationSource($source->title(), $source->summary(), $source->seoTitle(), $source->description(), $source->blocks());
    }

    public function save(Actor $actor, int $pageId, string $from, string $to, TranslationSource $translated): ?Message
    {
        $page = $this->editable($actor, $pageId, $to);
        $source = $page?->translation($from);
        if ($page === null || $source === null) {
            return new Message('The translation can’t be started: the page changed, or you can’t change it now.');
        }
        try {
            $this->editor->draftTranslation($page, $to, new PageContent(
                $translated->title,
                Slug::fromText($translated->title),
                $translated->summary,
                $translated->description,
                $translated->blocks,
                $source->socialImage(),
                $translated->seoTitle,
                $source->isNoindex(),
                $source->canonicalUrl(),
                $source->isExcludedFromSiteSearch(),
            ), $actor->userId);
        } catch (PageException $exception) {
            return $exception->text();
        } catch (InvalidBlocks) {
            return new Message('The translation’s blocks didn’t pass the checks, so it wasn’t saved. Try again.');
        }

        return null;
    }

    private function editable(Actor $actor, int $pageId, string $to): ?Page
    {
        $page = $this->pages->find($pageId);
        if ($page === null || !in_array($to, $this->site->locales, true) || $page->translation($to) !== null || !$this->authorizer->can($actor, PageAction::Edit->value, $page)) {
            return null;
        }

        return $page;
    }
}
