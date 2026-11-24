<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\AnalyticsRetention;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Core\Tests\Support\Analytics\AnalyticsFixture;

#[CoversClass(AnalyticsRetention::class)]
#[UsesNamespace('SightPress\Core')]
final class AnalyticsRetentionTest extends TestCase
{
    public function testKeepsTwentyFiveMonthsUntilSomeoneChoosesOneToTwentyFive(): void
    {
        $fixture = new AnalyticsFixture();
        $retention = $fixture->retention();
        self::assertSame(25, $retention->months());
        self::assertSame('2024-10-14', $retention->firstKeptDay('2026-11-14'));

        $retention->change(13, 7);
        self::assertSame(13, $retention->months());
        self::assertSame('2025-10-14', $retention->firstKeptDay('2026-11-14'));
        self::assertEquals([new SettingsChanged([AnalyticsRetention::SETTING], 7)], $fixture->events->events, 'audited');

        $retention->change(13, 7);
        self::assertCount(1, $fixture->events->events, 'no change, nothing to audit');
        $retention->change(25, null);
        self::assertSame('25', $fixture->settings->get(AnalyticsRetention::SETTING));
        self::assertCount(2, $fixture->events->events);
    }

    public function testRefusesOtherNumbersAndIgnoresAStoredValueThatDoesNotFit(): void
    {
        $fixture = new AnalyticsFixture();
        foreach (['0', '26', 'abc', '-3', '100'] as $stored) {
            $fixture->settings->set(AnalyticsRetention::SETTING, $stored);
            self::assertSame(25, $fixture->retention()->months(), $stored);
        }
        foreach ([0, 26] as $months) {
            try {
                $fixture->retention()->change($months, 1);
                self::fail('accepted ' . $months);
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Keep analytics for 1 to 25 months.', $exception->getMessage());
            }
        }
        self::assertSame([], $fixture->events->events);
    }
}
