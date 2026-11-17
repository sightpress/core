<?php

declare(strict_types=1);

namespace SightPress\Core\Branding;

use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Site\Contract\SiteSettings;

/**
 * The generated pictures (initials icon, wordmark, share picture) show the site name: when the name changes in the
 * settings, they are made again. Uploaded pictures stay.
 */
final readonly class RenameListener
{
    public function __construct(
        private BrandingService $branding,
        private SiteSettings $settings,
    ) {
    }

    public function __invoke(SettingsChanged $event): void
    {
        if ($event->has(SiteProfile::NAME)) {
            $this->branding->regenerate($this->settings->get(SiteProfile::NAME));
        }
    }
}
