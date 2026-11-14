<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A clock that only moves when the test says so.
 */
final class FixedClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now = new DateTimeImmutable('2026-11-14 12:00:00 UTC'))
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d seconds', $seconds));
    }
}
