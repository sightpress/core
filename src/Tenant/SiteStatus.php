<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * Where a site is in its life: only `enabled` sites answer requests (ADR-0003).
 */
enum SiteStatus: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Archived = 'archived';
    case Deleted = 'deleted';
}
