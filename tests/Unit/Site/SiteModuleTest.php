<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Site\Command\CheckRegistryCommand;
use SightPress\Core\Site\Command\UpgradeRegistryCommand;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Site\DbalSiteSettings;
use SightPress\Core\Site\SiteModule;

#[CoversClass(SiteModule::class)]
#[UsesNamespace('SightPress\Core\Module')]
#[UsesNamespace('SightPress\Core\Site')]
final class SiteModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersTheRegistryCommands(): void
    {
        $module = new SiteModule();
        $registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue([new ModuleCandidate($module, 'sightpress/core')])->resolve(new ModuleSelection()),
        );

        self::assertSame('site', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        self::assertSame(
            [
                CheckRegistryCommand::class,
                UpgradeRegistryCommand::class,
                \SightPress\Core\Site\Command\CreateSiteCommand::class,
                \SightPress\Core\Site\Command\ListSitesCommand::class,
                \SightPress\Core\Site\Command\EnableSiteCommand::class,
                \SightPress\Core\Site\Command\DisableSiteCommand::class,
                \SightPress\Core\Site\Command\ArchiveSiteCommand::class,
                \SightPress\Core\Site\Command\DeleteSiteCommand::class,
                \SightPress\Core\Site\Command\PurgeSiteCommand::class,
                \SightPress\Core\Site\Command\MigrateSitesCommand::class,
                \SightPress\Core\Site\Command\ChangeSiteDatabaseCommand::class,
            ],
            array_map(static fn ($registered) => $registered->item, $registry->commands()),
        );
        self::assertSame('SightPress\Core\Site\Migrations', $registry->migrations()[0]->item->namespace());
        self::assertTrue($registry->services()[SiteSettings::class]->item->public);
        self::assertSame(DbalSiteSettings::class, $registry->services()[SiteSettings::class]->item->class);
    }
}
