<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditModule;
use SightPress\Core\Audit\AuditRecorder;
use SightPress\Core\Audit\Http\AuditLogHandler;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;

#[CoversClass(AuditModule::class)]
#[UsesNamespace('SightPress\Core')]
final class AuditModuleTest extends TestCase
{
    public function testIsRequiredAndRecordsEveryChangeEvent(): void
    {
        $module = new AuditModule();
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));

        self::assertSame(['audit', true], [$module->manifest()->id, $module->manifest()->required]);
        $listeners = array_values(array_filter($registry->listeners(), static fn ($l) => $l->module === 'audit'));
        self::assertSame(AuditRecorder::EVENTS, array_map(static fn ($l) => $l->item->event, $listeners));
        self::assertSame(-100, $listeners[0]->item->priority);
        $routes = array_values(array_filter($registry->routes(), static fn ($r) => $r->module === 'audit'));
        self::assertSame(['/admin/audit', '/admin/audit.csv'], array_map(static fn ($r) => $r->item->path, $routes));
        self::assertSame(AuditLogHandler::class, $routes[1]->item->handler);
        $menu = array_values(array_filter($registry->adminMenu(), static fn ($m) => $m->module === 'audit'));
        self::assertSame(['audit.log', 'audit.view'], [$menu[0]->item->id, $menu[0]->item->permission]);
        self::assertSame('SightPress\Core\Audit\Migrations', array_values(array_filter($registry->migrations(), static fn ($m) => $m->module === 'audit'))[0]->item->namespace());
    }
}
