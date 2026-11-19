<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * An optional module, off by default, that needs {@see EventsModule}; it has no table and no description.
 */
final class CalendarModule implements ModuleInterface
{
    public const string ID = 'acme.calendar';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::ID, '1.0.0', [new ModuleRequirement(EventsModule::ID, '^1.0')]);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->routes()->add(new Route('acme.calendar.show', '/calendar', HelloHandler::class));
    }
}
