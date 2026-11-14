<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Container;

use LogicException;
use Psr\Container\ContainerInterface;

/**
 * Typed access to container entries.
 */
final class Services
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function get(ContainerInterface $container, string $class, ?string $id = null): object
    {
        $service = $container->get($id ?? $class);
        if (!$service instanceof $class) {
            throw new LogicException(sprintf(
                'Service "%s" is a %s, not a %s.',
                $id ?? $class,
                get_debug_type($service),
                $class,
            ));
        }

        return $service;
    }
}
