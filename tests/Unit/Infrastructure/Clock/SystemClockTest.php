<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Clock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Clock\SystemClock;

#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    public function testTellsTheTimeInUtc(): void
    {
        $now = new SystemClock()->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertEqualsWithDelta(time(), $now->getTimestamp(), 2);
    }
}
