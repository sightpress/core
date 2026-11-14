<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;

/**
 * An installable module, as a Composer package would ship it.
 */
final class ExampleModule implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('acme.example', '1.2.0', enabledByDefault: true);
    }

    public function register(ModuleRegistrar $registrar): void
    {
    }
}
