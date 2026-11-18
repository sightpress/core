<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use LogicException;
use Psr\Container\ContainerInterface;

/**
 * A container that has only the entries it is given, for unit tests.
 */
final readonly class ArrayContainer implements ContainerInterface
{
    /**
     * @param array<string, object> $entries
     */
    public function __construct(private array $entries = [])
    {
    }

    public function get(string $id): object
    {
        return $this->entries[$id] ?? throw new LogicException(sprintf('No entry "%s".', $id));
    }

    public function has(string $id): bool
    {
        return isset($this->entries[$id]);
    }
}
