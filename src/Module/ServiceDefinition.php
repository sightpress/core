<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use Closure;

/**
 * A service a module added: built by a factory, or autowired from a class.
 */
final readonly class ServiceDefinition
{
    /**
     * @param non-empty-string  $id
     * @param Closure|null      $factory `Closure(Psr\Container\ContainerInterface): object`
     * @param class-string|null $class   the class to autowire when there is no factory
     */
    private function __construct(
        public string $id,
        public ?Closure $factory,
        public ?string $class,
        public bool $public,
    ) {
    }

    /**
     * @param non-empty-string $id
     */
    public static function factory(string $id, Closure $factory, bool $public): self
    {
        return new self($id, $factory, null, $public);
    }

    /**
     * @param non-empty-string $id
     * @param class-string     $class
     */
    public static function autowire(string $id, string $class, bool $public): self
    {
        return new self($id, null, $class, $public);
    }
}
