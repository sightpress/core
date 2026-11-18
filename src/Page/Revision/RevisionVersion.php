<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Revision;

use SightPress\Core\Page\Entity\PageRevision;
use SightPress\Core\Rbac\Contract\PageState;

/**
 * A version of a page in one language ({@see RevisionHistory}): the revision, the workflow state the page was in when
 * it was saved, and the earlier revision it is compared with (null for the first one in that language).
 */
final readonly class RevisionVersion
{
    public function __construct(
        public PageRevision $revision,
        public ?PageState $state,
        public ?PageRevision $previous,
    ) {
    }
}
