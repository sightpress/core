<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Core\Tests\Support\Assistant\TestAssistantProvider;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;

/**
 * A package that adds a pretend AI service to the assistant ("Test AI", {@see TestAssistantProvider}), which never
 * leaves the server, so tests and the accessibility test site can use the assistant without an API key or a
 * network.
 */
final class TestAssistantModule implements ModuleInterface
{
    public const string ID = 'acme.test_ai';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::ID, '1.0.0', enabledByDefault: true);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $services = $registrar->services();
        $assistant = $registrar->assistant();
        $services->autowire(TestAssistantProvider::class);
        $assistant->addProvider(TestAssistantProvider::class);
    }
}
