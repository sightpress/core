<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use Psr\Container\ContainerInterface;

/**
 * The container a module's service factories get: it only hands out services the module may use (its own, core's,
 * and public services of modules it requires — ADR-0009).
 */
final readonly class ModuleScopedContainer implements ContainerInterface
{
    public function __construct(
        private ContainerInterface $container,
        private ModuleRegistry $registry,
        private string $module,
    ) {
    }

    public function get(string $id): mixed
    {
        $this->registry->assertCanUse($this->module, $id);

        return $this->container->get($id);
    }

    public function has(string $id): bool
    {
        return $this->registry->canUse($this->module, $id) && $this->container->has($id);
    }
}
