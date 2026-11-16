<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Contact;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Contact\ContactFormBlock;
use SightPress\Core\Contact\ContactMailer;
use SightPress\Core\Contact\ContactModule;
use SightPress\Core\Contact\Http\ContactFormHandler;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;

#[CoversClass(ContactModule::class)]
#[UsesNamespace('SightPress\Core')]
final class ContactModuleTest extends TestCase
{
    public function testRegistersTheBlockTheRouteAndTheViews(): void
    {
        $module = new ContactModule();
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));

        self::assertSame('contact', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        self::assertSame(['block', 'page'], array_map(static fn ($r) => $r->moduleId, $module->manifest()->requires));
        $types = array_values(array_filter($registry->blockTypes(), static fn ($registered): bool => $registered->module === 'contact'));
        self::assertSame([ContactFormBlock::class], array_map(static fn ($registered): string => $registered->item, $types));
        $routes = array_values(array_filter($registry->routes(), static fn ($registered): bool => $registered->module === 'contact'));
        self::assertSame([['contact.form', '/contact-form', ['POST'], ContactFormHandler::class]], array_map(static fn ($r) => [$r->item->name, $r->item->path, $r->item->methods, $r->item->handler], $routes));
        self::assertArrayHasKey(ContactMailer::class, $registry->services());
        $views = array_values(array_filter($registry->views(), static fn ($registered): bool => $registered->module === 'contact'));
        self::assertSame('contact', $views[0]->item->namespace);
        self::assertDirectoryExists($views[0]->item->directory);
    }
}
