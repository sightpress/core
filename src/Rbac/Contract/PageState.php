<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * The workflow states of a page (ADR-0006).
 */
enum PageState: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Published = 'published';
    case Archived = 'archived';
    case Trash = 'trash';
}
