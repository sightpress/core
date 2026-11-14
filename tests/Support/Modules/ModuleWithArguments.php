<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;

/**
 * A module that can't be discovered: its constructor needs an argument.
 */
final readonly class ModuleWithArguments implements ModuleInterface
{
    public function __construct(private string $id)
    {
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest($this->id, '1.0.0');
    }

    public function register(ModuleRegistrar $registrar): void
    {
    }
}
