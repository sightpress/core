<?php

declare(strict_types=1);

namespace SightPress\Core\Branding\Provisioning;

use SightPress\Core\Branding\BrandingService;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\SiteProvisioning;

/**
 * New site, step {@see PRIORITY}: generates the wordmark, the icons with the site's initials and the share image.
 */
final readonly class DefaultBranding
{
    public const int PRIORITY = SiteProvisioning::CONTENT + 50;

    public function __invoke(SiteProvisioning $event): void
    {
        Services::get($event->services, BrandingService::class)->regenerate();
        $event->report('Made the site\'s icons, wordmark and share image from its name; replace them with site:branding:set.');
    }
}
