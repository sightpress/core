<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Migration;

use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\ExampleMigrations;
use SightPress\Module\ModuleRegistrar;

#[CoversClass(MigrationConfigurationFactory::class)]
#[UsesNamespace('SightPress\Core\Module')]
final class MigrationConfigurationFactoryTest extends TestCase
{
    public function testEachModuleKeepsItsMigrationsInItsOwnNamespace(): void
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('user', register: self::migrations('SightPress\Core\User\Migrations')), 'sightpress/core'),
            new ModuleCandidate(new FakeModule('acme.events', register: self::migrations('Acme\Events\Migrations')), 'acme/events'),
        ])->resolve(new ModuleSelection()));

        $configuration = new MigrationConfigurationFactory()->create($registry);

        self::assertSame(
            ['SightPress\Core\User\Migrations' => __DIR__, 'Acme\Events\Migrations' => __DIR__],
            $configuration->getMigrationDirectories(),
        );
        $storage = $configuration->getMetadataStorageConfiguration();
        self::assertInstanceOf(TableMetadataStorageConfiguration::class, $storage);
        self::assertSame('migration_versions', $storage->getTableName());
        self::assertTrue($configuration->isTransactional());
        self::assertFalse($configuration->isDatabasePlatformChecked());
    }

    /**
     * @param non-empty-string $namespace
     *
     * @return \Closure(ModuleRegistrar): void
     */
    private static function migrations(string $namespace): \Closure
    {
        return static function (ModuleRegistrar $r) use ($namespace): void {
            $r->migrations()->add(new ExampleMigrations($namespace, __DIR__));
        };
    }
}
