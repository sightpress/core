<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteStatus;

/**
 * `site:disable <slug>`: Turns a site off for a while: visitors get a "temporarily unavailable" page.
 */
final readonly class DisableSiteCommand extends SiteStatusCommand
{
    public function description(): string
    {
        return 'Turns a site off for a while: visitors get a "temporarily unavailable" page.';
    }

    protected function status(): SiteStatus
    {
        return SiteStatus::Disabled;
    }

    protected function consequence(Site $site): string
    {
        return sprintf('visitors get a "temporarily unavailable" page (503) until you run site:enable %s.', $site->slug);
    }
}
