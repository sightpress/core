<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

/**
 * A minimal tenant project (config/tenant.php + config/sites.php) in a temporary folder.
 */
final class TestProject
{
    public readonly TemporaryDirectory $directory;

    /**
     * @param array<string, mixed> $tenant extra tenant settings
     * @param list<array<string, mixed>> $sites
     */
    public function __construct(array $tenant = [], array $sites = [])
    {
        $this->directory = new TemporaryDirectory();
        $this->directory->write('config/tenant.php', '<?php return ' . var_export($tenant + [
            'log' => ['path' => $this->directory->path . '/var/log/test.log'],
        ], true) . ';');
        $this->directory->write('config/sites.php', '<?php return ' . var_export([
            'version' => 1,
            'sites' => $sites,
        ], true) . ';');
    }

    public function path(): string
    {
        return $this->directory->path;
    }

    public function log(): string
    {
        $file = $this->directory->path . '/var/log/test.log';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    public function remove(): void
    {
        $this->directory->remove();
    }
}
