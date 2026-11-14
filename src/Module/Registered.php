<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

/**
 * Something a module registered, tied to the module that registered it.
 *
 * @template-covariant T
 */
final readonly class Registered
{
    /**
     * @param non-empty-string $module id of the module that registered it
     * @param T                $item
     */
    public function __construct(
        public string $module,
        public mixed $item,
    ) {
    }
}
