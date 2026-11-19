<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Message;

/**
 * Translations drafted by the AI assistant (A-6): the page's texts to translate, and the draft saved as a translation
 * marked as the assistant's, which can't be published until a person edits it or says they checked it.
 */
interface TranslationDrafts
{
    /**
     * The page in `$from`, when the person may edit it, it is written in `$from` and not yet in `$to`.
     */
    public function source(Actor $actor, int $pageId, string $from, string $to): ?TranslationSource;

    /**
     * Saves the translation into `$to` as a draft marked as the assistant's; the same settings as in `$from` (layout,
     * share picture, search options) and an address made from the translated title.
     *
     * @return Message|null why it couldn't be saved, or null when it was
     */
    public function save(Actor $actor, int $pageId, string $from, string $to, TranslationSource $translated): ?Message;
}
