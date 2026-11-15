<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteStatus;

/**
 * `site:enable <slug>`: Turns a site on, so it answers again.
 */
final readonly class EnableSiteCommand extends SiteStatusCommand
{
    public function description(): string
    {
        return 'Turns a site on, so it answers again.';
    }

    protected function status(): SiteStatus
    {
        return SiteStatus::Enabled;
    }

    protected function consequence(Site $site): string
    {
        return sprintf('it answers at %s.', implode(', ', $site->domains));
    }
}
