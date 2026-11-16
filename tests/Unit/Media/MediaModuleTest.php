<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use DI\ContainerBuilder;
use Intervention\Image\Interfaces\ImageManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\MediaMigrations;
use SightPress\Core\Media\MediaModule;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Site\SiteModule;

#[CoversClass(MediaModule::class)]
#[CoversClass(MediaMigrations::class)]
#[UsesNamespace('SightPress\Core\Module')]
#[UsesNamespace('SightPress\Core\Site')]
final class MediaModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersItsTableAndServices(): void
    {
        $module = new MediaModule();
        $registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue([
                new ModuleCandidate(new SiteModule(), 'sightpress/core'),
                new ModuleCandidate($module, 'sightpress/core'),
            ])->resolve(new ModuleSelection()),
        );

        self::assertSame('media', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        self::assertSame(['site'], array_map(static fn ($r) => $r->moduleId, $module->manifest()->requires));
        self::assertSame('SightPress\Core\Media\Migrations', $registry->migrations()[1]->item->namespace());
        self::assertDirectoryExists($registry->migrations()[1]->item->directory());
        self::assertTrue($registry->services()[MediaUploader::class]->item->public);
        self::assertTrue($registry->services()[MediaRepository::class]->item->public);

        $factory = $registry->services()[ImageManagerInterface::class]->item->factory;
        self::assertNotNull($factory);
        self::assertInstanceOf(ImageManagerInterface::class, $factory(new ContainerBuilder()->build()));
    }
}
