<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Assistant;

use SightPress\Core\Infrastructure\Assistant\AssistantAccess;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\ArrayContainer;

/**
 * The assistant panel of a site without the AI assistant, for screens built by hand in tests: never shown.
 */
final class NoAssistant
{
    public static function access(): AssistantAccess
    {
        return new AssistantAccess(new ArrayContainer([]), ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([])->resolve(new ModuleSelection())));
    }
}
