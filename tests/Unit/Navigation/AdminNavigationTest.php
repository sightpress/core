<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Navigation;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Navigation\AdminNavigation;
use SightPress\Core\Navigation\AdminNavigationEntry;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\HelloHandler;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;

#[CoversClass(AdminNavigation::class)]
#[CoversClass(AdminNavigationEntry::class)]
#[UsesNamespace('SightPress\Core')]
final class AdminNavigationTest extends TestCase
{
    public function testShowsOnlyTheEntriesThePersonMayUseSortedWithTheCurrentOneMarked(): void
    {
        $navigation = new AdminNavigation([
            new AdminMenuItem('users', 'Users', 'users', 'user.manage', position: 10),
            new AdminMenuItem('dashboard', 'Dashboard', 'dashboard', position: -100),
            new AdminMenuItem('pages', 'Pages', 'pages', 'page.create'),
            new AdminMenuItem('media', 'Media', 'media', 'media.upload'),
            new AdminMenuItem('pages.trash', 'Bin', 'trash', 'page.delete', 'pages'),
            new AdminMenuItem('pages.new', 'New page', 'new', 'page.create', 'pages'),
            new AdminMenuItem('users.roles', 'Roles', 'roles', 'role.manage', 'users'),
        ], [
            'dashboard' => '/admin',
            'pages' => '/admin/pages',
            'new' => '/admin/pages/new',
            'trash' => '/admin/pages/bin',
            'media' => '/admin/media',
            'users' => '/admin/users',
            'roles' => '/admin/users/roles',
        ]);

        $entries = $navigation->for(['page.create', 'media.upload', 'role.manage'], '/admin/pages/new/');

        self::assertSame(['dashboard', 'media', 'pages'], array_map(static fn (AdminNavigationEntry $e): string => $e->id, $entries));
        self::assertSame('/admin', $entries[0]->url);
        self::assertSame('Dashboard', $entries[0]->label);
        self::assertFalse($entries[0]->current);
        self::assertFalse($entries[2]->current);
        self::assertSame(['pages.new'], array_map(static fn (AdminNavigationEntry $e): string => $e->id, $entries[2]->children));
        self::assertTrue($entries[2]->children[0]->current);
        self::assertSame([], $entries[2]->children[0]->children);

        $current = array_filter(
            $navigation->for(['page.create'], '/admin/pages/42/edit'),
            static fn (AdminNavigationEntry $e): bool => $e->current,
        );
        self::assertSame(['pages'], array_map(static fn (AdminNavigationEntry $e): string => $e->id, array_values($current)));
        self::assertTrue($navigation->for([], '/admin')[0]->current);
        self::assertFalse($navigation->for([], '/administrators')[0]->current);
    }

    public function testIsBuiltFromWhatTheModulesRegistered(): void
    {
        $module = new FakeModule('acme.events', register: static function (ModuleRegistrar $r): void {
            $r->routes()->add(new Route('acme.events', '/admin/events', HelloHandler::class));
            $r->adminMenu()->add(new AdminMenuItem('events', 'Events', 'acme.events'));
        });
        $registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue([new ModuleCandidate($module, 'acme/events')])->resolve(new ModuleSelection()),
        );

        $entries = AdminNavigation::fromRegistry($registry)->for([], '/admin/events');

        self::assertCount(1, $entries);
        self::assertSame('/admin/events', $entries[0]->url);
        self::assertTrue($entries[0]->current);
    }

    public function testRefusesEntriesThatLinkToAMissingRoute(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Admin menu entry "events" links to route "acme.events", which no module that is on registered.');

        new AdminNavigation([new AdminMenuItem('events', 'Events', 'acme.events')]);
    }

    public function testRefusesEntriesThatLinkToAPathWithPlaceholders(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('whose path /admin/events/{id} needs values');

        new AdminNavigation([new AdminMenuItem('events', 'Events', 'acme.events')], ['acme.events' => '/admin/events/{id}']);
    }
}
