<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * Reads `config/tenant.php` from the project folder.
 */
final readonly class TenantConfigLoader
{
    /**
     * @param array<string, string> $environment environment variables
     */
    public function load(string $projectDir, array $environment): TenantConfig
    {
        $file = rtrim($projectDir, '/') . '/config/tenant.php';
        if (!is_file($file)) {
            throw new TenantConfigException(sprintf('The tenant configuration file is missing: %s.', $file));
        }

        $config = (static fn (): mixed => require $file)();
        if (!is_array($config)) {
            throw new TenantConfigException(sprintf('%s must return an array.', $file));
        }

        return TenantConfig::fromArray($config, $projectDir, $environment);
    }
}
