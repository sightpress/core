<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteStatus;

/**
 * `site:delete <slug>`: Deletes a site, softly: it stops answering and its data is kept until site:purge.
 */
final readonly class DeleteSiteCommand extends SiteStatusCommand
{
    public function description(): string
    {
        return 'Deletes a site, softly: it stops answering and its data is kept until site:purge.';
    }

    protected function status(): SiteStatus
    {
        return SiteStatus::Deleted;
    }

    protected function consequence(Site $site): string
    {
        return sprintf('it no longer answers (404) and its data is kept. Run site:enable %1$s to bring it back, or site:purge %1$s to remove it for good.', $site->slug);
    }
}
