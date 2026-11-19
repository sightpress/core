<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;

/**
 * An external module that makes its own content searchable through `search()`, as a third-party module would.
 */
final class ExhibitionsModule implements ModuleInterface
{
    public const string ID = 'acme.exhibitions';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::ID, '1.0.0', required: true);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->services()->autowire(ExhibitionsSearchProvider::class);
        $registrar->search()->addProvider(ExhibitionsSearchProvider::class);
    }
}
