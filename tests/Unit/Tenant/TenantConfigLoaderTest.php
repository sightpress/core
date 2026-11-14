<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\Environment;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tenant\TenantConfigException;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(TenantConfigLoader::class)]
#[UsesClass(TenantConfig::class)]
#[UsesClass(Environment::class)]
final class TenantConfigLoaderTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testItLoadsTheTenantConfigFile(): void
    {
        $this->directory->write('config/tenant.php', "<?php return ['sites_dir' => '/data/sites'];");

        $config = new TenantConfigLoader()->load($this->directory->path . '/', ['APP_ENV' => 'dev']);

        self::assertSame('/data/sites', $config->sitesDir);
        self::assertSame(Environment::Dev, $config->environment);
    }

    public function testAMissingFileIsReported(): void
    {
        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage('The tenant configuration file is missing: ' . $this->directory->path);

        new TenantConfigLoader()->load($this->directory->path, []);
    }

    public function testTheFileMustReturnAnArray(): void
    {
        $this->directory->write('config/tenant.php', '<?php return 42;');

        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage('config/tenant.php must return an array.');

        new TenantConfigLoader()->load($this->directory->path, []);
    }
}
