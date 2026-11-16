<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageMigrations;
use SightPress\Core\Page\PageModule;
use SightPress\Core\Page\PageRepository;

#[CoversClass(PageModule::class)]
#[CoversClass(PageMigrations::class)]
#[UsesNamespace('SightPress\Core')]
final class PageModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersItsTablesAndServices(): void
    {
        $module = new PageModule();
        $registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()),
        );

        self::assertSame('page', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        self::assertSame(['site', 'rbac', 'block'], array_map(static fn ($r) => $r->moduleId, $module->manifest()->requires));
        $migrations = array_values(array_filter(
            $registry->migrations(),
            static fn ($registered): bool => $registered->module === 'page',
        ));
        self::assertCount(1, $migrations);
        self::assertSame('SightPress\Core\Page\Migrations', $migrations[0]->item->namespace());
        self::assertDirectoryExists($migrations[0]->item->directory());
        self::assertTrue($registry->services()[PageRepository::class]->item->public);
        self::assertTrue($registry->services()[PageEditor::class]->item->public);
        self::assertSame(\SightPress\Core\Page\PublishedPages::class, $registry->services()[\SightPress\Core\Page\Contract\PublicPages::class]->item->class);
    }
}
