<?php

declare(strict_types=1);

namespace SightPress\Core\Branding\Command;

use SightPress\Core\Branding\BrandingService;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;

/**
 * The branding of a site named by its slug, for the `site:branding:*` commands.
 */
final readonly class SiteBrandings
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
    ) {
    }

    /**
     * @return BrandingService|string the service, or why there is none (for the operator)
     */
    public function forSlug(?string $slug): BrandingService|string
    {
        $site = $this->sites->find(trim((string) $slug));
        if ($site === null || $site->status === SiteStatus::Deleted) {
            return sprintf('There is no site "%s". See the sites with site:list.', $slug);
        }

        return Services::get($this->containers->forSite($site), BrandingService::class);
    }
}
