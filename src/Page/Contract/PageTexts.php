<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use SightPress\Core\Rbac\Contract\Actor;

/**
 * The saved text of a page a person may edit (the AI assistant's "share this page" and Suggest buttons).
 */
interface PageTexts
{
    /**
     * @return PageText|null null when there is no such page or translation, or the person may not edit it
     */
    public function forEditing(Actor $actor, int $pageId, string $locale): ?PageText;
}
