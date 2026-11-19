<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Access\PermissionCatalogue;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;

#[CoversClass(PermissionCatalogue::class)]
#[UsesNamespace('SightPress\Core')]
final class PermissionCatalogueTest extends TestCase
{
    public function testListsWhatTheModulesRegistered(): void
    {
        $catalogue = PermissionCatalogue::fromRegistry(ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()),
        ));

        self::assertCount(20, $catalogue->all());
        self::assertTrue($catalogue->has('page.publish'));
        self::assertSame('Publish pages', $catalogue->get('page.publish')?->label);
        self::assertSame('user', $catalogue->moduleOf('user.manage'));
        self::assertFalse($catalogue->has('acme.missing'));
        self::assertNull($catalogue->get('acme.missing'));
        self::assertNull($catalogue->moduleOf('acme.missing'));
        self::assertSame([], new PermissionCatalogue()->all());
    }
}
