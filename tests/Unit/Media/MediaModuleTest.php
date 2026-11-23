<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use DI\ContainerBuilder;
use Intervention\Image\Interfaces\ImageManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Contract\MediaFormats;
use SightPress\Core\Media\Contract\MediaLibrary;
use SightPress\Core\Media\Contract\MediaUrlGenerator;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Media\Http\MediaFileHandler;
use SightPress\Core\Media\MediaAccess;
use SightPress\Core\Media\MediaMigrations;
use SightPress\Core\Media\MediaModule;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Media\DeliveryMediaUrlGenerator;
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
        self::assertTrue($registry->services()[MediaUrlGenerator::class]->item->public);
        self::assertSame(MediaTypes::class, $registry->services()[MediaFormats::class]->item->class);
        self::assertSame(MediaRepository::class, $registry->services()[MediaLibrary::class]->item->class);
        self::assertSame(DeliveryMediaUrlGenerator::class, $registry->services()[MediaUrlGenerator::class]->item->class);
        self::assertArrayHasKey(MediaFileHandler::class, $registry->services());
        self::assertArrayHasKey(MediaAccess::class, $registry->services());
        self::assertTrue($registry->services()[\SightPress\Core\Media\UploadAllowance::class]->item->public);
        self::assertArrayHasKey(\SightPress\Core\Http\Upload\RequestTooLargeHandler::class, $registry->services());
        self::assertContains(\SightPress\Core\Media\Command\SystemCheckCommand::class, array_map(static fn ($c) => $c->item, $registry->commands()));
        self::assertSame(
            [
                ['media.request_too_large', '/_sightpress/request-too-large', ['GET', 'HEAD']],
                ['media.file', '/media/{key:[0-9a-f]{32}}/{name}', ['GET', 'HEAD']],
                ['media.variant', '/media/{key:[0-9a-f]{32}}/{width:\\d{1,5}}w/{name}', ['GET', 'HEAD']],
            ],
            array_map(static fn ($r) => [$r->item->name, $r->item->path, $r->item->methods], $registry->routes()),
        );

        $factory = $registry->services()[ImageManagerInterface::class]->item->factory;
        self::assertNotNull($factory);
        self::assertInstanceOf(ImageManagerInterface::class, $factory(new ContainerBuilder()->build()));
    }
}
