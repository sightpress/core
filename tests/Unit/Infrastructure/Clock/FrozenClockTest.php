<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Clock;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Clock\FrozenClock;

#[CoversClass(FrozenClock::class)]
final class FrozenClockTest extends TestCase
{
    public function testAlwaysSaysTheSameTimeInUtc(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T10:00:00+01:00'));

        self::assertSame('2026-01-01T09:00:00+00:00', $clock->now()->format(DATE_ATOM));
        self::assertEquals($clock->now(), $clock->now());
    }
}
