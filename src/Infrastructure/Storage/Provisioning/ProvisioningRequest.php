<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Storage\Provisioning;

/**
 * What to set up for one site. `publicPrefix` is the only part of the bucket a CDN may read directly: private files
 * are never stored under it, and they keep going through the site, which checks their signature.
 */
final readonly class ProvisioningRequest
{
    /**
     * @param list<string> $adminOrigins origins allowed to upload directly from the browser
     */
    public function __construct(
        public string $bucket,
        public string $region,
        public string $prefix,
        public array $adminOrigins,
        public string $siteHost,
        public ?string $customDomain = null,
    ) {
    }

    public function incomingPrefix(): string
    {
        return $this->prefix . '/incoming/';
    }

    public function publicPrefix(): string
    {
        return $this->prefix . '/public/';
    }
}
