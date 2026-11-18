<?php

declare(strict_types=1);

namespace SightPress\Core\Site;

use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;

/**
 * The site's name, languages, default language and time zone are read when its container is built ({@see SiteProfile::display()}).
 * When the admins change one of them, the container is forgotten, so the next request (also in a long-running
 * worker) builds a new one with the new values.
 */
final readonly class DisplayChanged
{
    public function __construct(
        private SiteContainerFactory $containers,
        private Site $site,
    ) {
    }

    public function __invoke(SettingsChanged $event): void
    {
        if ($event->has(SiteProfile::NAME) || $event->has(SiteProfile::LOCALES) || $event->has(SiteProfile::DEFAULT_LOCALE) || $event->has(SiteProfile::DEFAULT_TIMEZONE)) {
            $this->containers->evict($this->site->slug);
        }
    }
}
