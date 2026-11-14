<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * What can be done to a page; {@see \SightPress\Core\Rbac\PageVoter} decides each one. The workflow transitions
 * (ADR-0006) have the transition's name.
 */
enum PageAction: string
{
    case View = 'page.view';
    case Edit = 'page.edit';
    case Submit = 'page.submit';
    case Reject = 'page.reject';
    case Approve = 'page.approve';
    case Publish = 'page.publish';
    case Unpublish = 'page.unpublish';
    case Archive = 'page.archive';
    case Restore = 'page.restore';
    case Trash = 'page.trash';
    case Untrash = 'page.untrash';
    case Purge = 'page.purge';
}
