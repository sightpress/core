<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteStatus;

/**
 * `site:archive <slug>`: Archives a site: visitors are told it is gone, and its data is kept.
 */
final readonly class ArchiveSiteCommand extends SiteStatusCommand
{
    public function description(): string
    {
        return 'Archives a site: visitors are told it is gone, and its data is kept.';
    }

    protected function status(): SiteStatus
    {
        return SiteStatus::Archived;
    }

    protected function consequence(Site $site): string
    {
        return 'visitors are told the site is gone (410). Its data is kept.';
    }
}
