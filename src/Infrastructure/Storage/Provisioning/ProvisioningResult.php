<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Storage\Provisioning;

/**
 * What provisioning did. `cdnHost` is where the CDN will answer once it is deployed; the site isn't switched to it.
 */
final readonly class ProvisioningResult
{
    /**
     * @param list<string> $created
     * @param list<string> $unchanged
     */
    public function __construct(
        public array $created,
        public array $unchanged,
        public ?string $cdnHost = null,
    ) {
    }
}
