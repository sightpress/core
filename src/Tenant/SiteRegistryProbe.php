<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * Tells whether the site registry (`config/sites.php`, ADR-0002) lists any site.
 *
 * Until the full registry exists, the kernel uses this to show the "No site configured" page instead of a plain
 * "Page not found" page on a fresh install.
 */
final readonly class SiteRegistryProbe
{
    public function __construct(private string $registryPath)
    {
    }

    public function hasSites(): bool
    {
        if (!is_file($this->registryPath)) {
            return false;
        }

        $path = $this->registryPath;
        $registry = (static fn (): mixed => require $path)();

        return is_array($registry) && is_array($registry['sites'] ?? null) && $registry['sites'] !== [];
    }
}
