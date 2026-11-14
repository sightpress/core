<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use Closure;
use Psr\Container\ContainerInterface;

use function DI\autowire;
use function DI\factory;

/**
 * Turns the services modules registered into PHP-DI definitions. A factory gets a {@see ModuleScopedContainer},
 * so it can only reach the services its module may use; no module may replace a core service.
 */
final class ModuleServices
{
    /**
     * @param list<string> $coreIds ids of core's own services
     *
     * @return array<string, mixed>
     */
    public static function definitions(ModuleRegistry $registry, array $coreIds): array
    {
        $definitions = [];
        foreach ($registry->services() as $id => $registered) {
            if (in_array($id, $coreIds, true)) {
                throw new ModuleException(sprintf(
                    'Module "%s" registers service "%s", which is a core service. Use another id.',
                    $registered->module,
                    $id,
                ));
            }

            $service = $registered->item;
            $definitions[$id] = $service->factory instanceof Closure
                ? factory(self::scoped($service->factory, $registry, $registered->module, $id))
                : autowire($service->class);
        }

        return $definitions;
    }

    /**
     * @return Closure(ContainerInterface): object
     */
    private static function scoped(Closure $factory, ModuleRegistry $registry, string $module, string $id): Closure
    {
        return static function (ContainerInterface $container) use ($factory, $registry, $module, $id): object {
            $service = $factory(new ModuleScopedContainer($container, $registry, $module));
            if (!is_object($service)) {
                throw new ModuleException(sprintf(
                    'The factory of service "%s" (module "%s") returned %s instead of an object.',
                    $id,
                    $module,
                    get_debug_type($service),
                ));
            }

            return $service;
        };
    }
}
