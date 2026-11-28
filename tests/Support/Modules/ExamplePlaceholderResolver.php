<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\Placeholder\PlaceholderContext;
use SightPress\Module\Placeholder\PlaceholderResolver;

/**
 * A module's placeholder values, for registry tests.
 */
final class ExamplePlaceholderResolver implements PlaceholderResolver
{
    public function resolve(string $name, PlaceholderContext $context): ?string
    {
        return $name === 'acme_events.venue' ? 'Casa do Fado' : null;
    }
}
