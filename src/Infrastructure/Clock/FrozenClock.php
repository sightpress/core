<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A clock that always says the same time, in UTC: for output that must be the same on every run, such as a theme's
 * preview page.
 */
final readonly class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now->setTimezone(new \DateTimeZone('UTC'));
    }
}
