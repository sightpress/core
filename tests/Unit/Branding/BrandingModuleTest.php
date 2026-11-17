<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Branding;

use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Branding\BrandingModule;
use SightPress\Core\Branding\BrandingService;
use SightPress\Core\Branding\RenameListener;
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Branding\Provisioning\DefaultBranding;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Site\Contract\FirstAdmin;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\Tests\Support\BrandingFixture;

#[CoversClass(BrandingModule::class)]
#[CoversClass(DefaultBranding::class)]
#[UsesNamespace('SightPress\Core')]
final class BrandingModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersItsRoutesCommandsAndSteps(): void
    {
        $module = new BrandingModule();
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));

        self::assertSame(['branding', true], [$module->manifest()->id, $module->manifest()->required]);
        self::assertSame(['site', 'rbac', 'media', 'page', 'admin'], array_map(static fn ($r) => $r->moduleId, $module->manifest()->requires));
        self::assertTrue($registry->services()[BrandingService::class]->item->public);
        $routes = array_values(array_filter($registry->routes(), static fn ($r) => $r->module === 'branding'));
        self::assertSame(
            ['/favicon.ico', '/apple-touch-icon.png', '/icon.svg', '/icon-192.png', '/icon-512.png', '/branding/{file}', '/site.webmanifest', '/admin/settings/branding'],
            array_map(static fn ($r) => $r->item->path, $routes),
        );
        self::assertSame('branding.icon_192_png', $routes[3]->item->name);
        $commands = array_values(array_filter($registry->commands(), static fn ($c) => $c->module === 'branding'));
        self::assertCount(3, $commands);
        $listeners = array_values(array_filter($registry->listeners(), static fn ($l) => $l->module === 'branding'));
        self::assertSame([SiteProvisioning::class, DefaultBranding::class, 50], [$listeners[1]->item->event, $listeners[1]->item->listener, $listeners[1]->item->priority]);
        self::assertSame([SettingsChanged::class, RenameListener::class], [$listeners[2]->item->event, $listeners[2]->item->listener]);
        $menu = array_values(array_filter($registry->adminMenu(), static fn ($m) => $m->module === 'branding'));
        self::assertSame(['branding.admin', 'settings.manage', 'admin.settings'], [$menu[0]->item->id, $menu[0]->item->permission, $menu[0]->item->parent]);
    }

    public function testANewSiteGetsTheGeneratedPictures(): void
    {
        $fixture = new BrandingFixture();
        $event = new SiteProvisioning($fixture->site, new Container([BrandingService::class => $fixture->service()]), new FirstAdmin('a@example.org', 'A', 'B'), 'https://demo.example');

        new DefaultBranding()($event);

        self::assertNotSame('', $fixture->service()->state()->version);
        self::assertSame(['Made the site\'s icons, wordmark and share image from its name; replace them with site:branding:set.'], $event->lines());
        $fixture->remove();
    }
}
