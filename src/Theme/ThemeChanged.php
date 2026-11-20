<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Theme\Contract\ThemeApplied;
use SightPress\Core\Theme\Contract\ThemeColoursChanged;

/**
 * The site's theme and colours are read when its container is built (template paths, Twig, `tokens.css`, asset
 * versions). When they change, the container is forgotten, so the next request (also in a long-running worker) shows
 * the new look.
 */
final readonly class ThemeChanged
{
    public function __construct(
        private SiteContainerFactory $containers,
        private Site $site,
    ) {
    }

    public function __invoke(ThemeApplied|ThemeColoursChanged $event): void
    {
        $this->containers->evict($this->site->slug);
    }
}
