<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Logging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Tenant\Environment;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(LoggerFactory::class)]
#[UsesClass(TenantConfig::class)]
#[UsesClass(Environment::class)]
final class LoggerFactoryTest extends TestCase
{
    public function testItWritesToTheConfiguredFileAtTheConfiguredLevel(): void
    {
        $directory = new TemporaryDirectory();
        $file = $directory->path . '/app.log';
        $config = TenantConfig::fromArray(['log' => ['path' => $file, 'level' => 'warning']], $directory->path);

        $logger = new LoggerFactory($config)->create('sites');
        $logger->notice('Not written');
        $logger->warning('Site {slug} disabled', ['slug' => 'demo']);
        $logger->close();

        $log = (string) file_get_contents($file);
        $directory->remove();

        self::assertSame('sites', $logger->getName());
        self::assertStringNotContainsString('Not written', $log);
        self::assertStringContainsString('sites.WARNING: Site demo disabled', $log);
        self::assertStringContainsString('+00:00', $log);
    }
}
