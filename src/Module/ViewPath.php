<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

/**
 * A folder of templates a module added under a namespace (`@<namespace>/<file>`).
 */
final readonly class ViewPath
{
    /**
     * @param non-empty-string $namespace
     * @param non-empty-string $directory absolute path
     */
    public function __construct(
        public string $namespace,
        public string $directory,
    ) {
    }
}
