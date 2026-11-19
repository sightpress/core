<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;
use SightPress\Module\Setting\Setting;
use SightPress\Module\Setting\SettingType;

/**
 * An optional module, on by default, as a Composer package would ship it: a public route (`/events`), an admin
 * screen in the menu (`/admin/events`), a setting, a listener and a table (`acme_event`).
 */
final class EventsModule implements ModuleInterface
{
    public const string ID = 'acme.events';

    /**
     * What `composer.json` says about it for the *Features* screen (`extra.sightpress.features`).
     */
    public const array ABOUT = [
        'name' => 'Events',
        'description' => 'A calendar of events, with a page for each one.',
        'when-off' => 'The events pages answer “not found” and the Events screen leaves the admin panel.',
    ];

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::ID, '1.0.0', enabledByDefault: true);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new ExampleMigrations('SightPress\\Core\\Tests\\Support\\Migrations\\Events', dirname(__DIR__) . '/Migrations/Events'));
        $registrar->routes()->add(new Route('acme.events.list', '/events', HelloHandler::class));
        $registrar->routes()->add(new Route('acme.events.admin', '/admin/events', HelloHandler::class));
        $registrar->adminMenu()->add(new AdminMenuItem('acme.events.admin', 'Events', 'acme.events.admin', position: 80));
        $registrar->settings()->add(new Setting('acme.events.title', 'Events page title', SettingType::Text));
        $registrar->services()->factory(RecordingListener::class, static fn (): RecordingListener => new RecordingListener('events'));
        $registrar->subscribers()->listen(ExampleEvent::class, RecordingListener::class);
    }
}
