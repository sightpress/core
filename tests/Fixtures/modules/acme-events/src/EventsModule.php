<?php

declare(strict_types=1);

namespace Acme\Events;

use SightPress\Module\Access\Permission;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\Event\PageTransitioned;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;
use SightPress\Module\Setting\Setting;
use SightPress\Module\Setting\SettingType;

/**
 * The "events" feature of the module tutorial, written only against module-interface: a permission, an admin screen
 * with a menu item, a block type, a migration, public pages with `Event` JSON-LD and a Markdown version, sitemap
 * entries, search documents, a scheduled job, translations and a listener on a core event.
 */
final class EventsModule implements ModuleInterface
{
    public const string ID = 'acme.events';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::ID, '1.0.0', enabledByDefault: true);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $services = $registrar->services();
        foreach ([EventStore::class, AdminHandler::class, PublicHandler::class, SeoEntries::class, SearchDocuments::class, ReminderJob::class, PublishedCounter::class, EventCardBlock::class] as $class) {
            $services->autowire($class);
        }

        $registrar->permissions()->add(new Permission('events.manage', 'Manage events', 'Add, change and delete events.', ['admin', 'editor']));
        $registrar->routes()->add(new Route('acme.events.admin', '/admin/events', AdminHandler::class, permission: 'events.manage'));
        $registrar->routes()->add(new Route('acme.events.add', '/admin/events', AdminHandler::class, ['POST'], permission: 'events.manage', critical: true));
        $registrar->routes()->add(new Route('acme.events.show', '/events/{slug}', PublicHandler::class));
        $registrar->adminMenu()->add(new AdminMenuItem('acme.events', 'Events', 'acme.events.admin', permission: 'events.manage', position: 45));
        $registrar->blockTypes()->add(EventCardBlock::class);
        $registrar->migrations()->add(new Migrations());
        $registrar->views()->add('acme_events', dirname(__DIR__) . '/templates');
        $registrar->translations()->add(dirname(__DIR__) . '/translations');
        $registrar->settings()->add(new Setting('events.page_size', 'Events per page', SettingType::Integer, 20));
        $registrar->seo()->addProvider(SeoEntries::class);
        $registrar->search()->addProvider(SearchDocuments::class);
        $registrar->schedule()->add(ReminderJob::class);
        $registrar->subscribers()->listen(PageTransitioned::class, PublishedCounter::class);
    }
}
