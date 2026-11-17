<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\EvenTiming;

#[CoversClass(EvenTiming::class)]
final class EvenTimingTest extends TestCase
{
    public function testFastWorkWaitsUntilTheBudgetIsUsed(): void
    {
        $start = hrtime(true);

        self::assertSame('done', new EvenTiming(20_000)->run(static fn (): string => 'done'));

        self::assertGreaterThanOrEqual(20_000_000, hrtime(true) - $start);
    }

    public function testSlowWorkDoesNotWaitAnyLonger(): void
    {
        $start = hrtime(true);

        new EvenTiming(0)->run(static fn () => usleep(5000));

        self::assertLessThan(500_000_000, hrtime(true) - $start);
    }

    public function testWaitsEvenWhenTheWorkFails(): void
    {
        $start = hrtime(true);
        $caught = null;
        try {
            new EvenTiming(10_000)->run(static fn () => throw new \RuntimeException('no'));
        } catch (\RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        self::assertSame('no', $caught, 'the exception is not swallowed');
        self::assertGreaterThanOrEqual(10_000_000, hrtime(true) - $start);
    }
}
