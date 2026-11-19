<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\AnalyticsRollup;
use SightPress\Core\Analytics\AnalyticsRollupJob;
use SightPress\Core\Analytics\AnalyticsStore;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Core\Tests\Support\Analytics\AnalyticsFixture;
use SightPress\Core\Tests\Support\ArrayContainer;

#[CoversClass(AnalyticsRollup::class)]
#[CoversClass(AnalyticsRollupJob::class)]
#[UsesNamespace('SightPress\Core')]
final class AnalyticsRollupTest extends TestCase
{
    public function testFinishedDaysBecomeFiguresAndTheirRawHitsAndSaltsAreDeleted(): void
    {
        $fixture = new AnalyticsFixture(now: '2026-11-14 12:00 UTC');
        $fixture->view(1, at: '2026-11-13 09:00 UTC');
        $fixture->view(1, at: '2026-11-13 10:00 UTC');
        $fixture->view(2, ip: '192.0.2.11', at: '2026-11-13 11:00 UTC');
        $fixture->view(1, at: '2026-11-14 09:00 UTC');
        $rollup = $fixture->rollup();

        self::assertSame(['days' => ['2026-11-13'], 'purged' => 0], $rollup->run($fixture->clock->now()));

        self::assertSame(['2026-11-14'], array_column($fixture->rows(AnalyticsStore::HITS), 'day_on'), 'today stays raw');
        self::assertSame(['2026-11-14'], array_column($fixture->rows(AnalyticsStore::SALTS), 'day_on'));
        $figures = $fixture->store->figures(['visitors', 'views'], '', '2026-11-13', '2026-11-13');
        self::assertSame([['visitors', 2], ['views', 3]], self::values($figures, ['visitors', 'views']));

        self::assertSame(['days' => [], 'purged' => 0], $rollup->run($fixture->clock->now()), 'safe to run again');
        self::assertSame([['visitors', 2], ['views', 3]], self::values($fixture->store->figures(['visitors', 'views'], '', '2026-11-13', '2026-11-13'), ['visitors', 'views']));
    }

    public function testAHitThatArrivesAfterItsDayWasRolledUpIsAdded(): void
    {
        $fixture = new AnalyticsFixture(now: '2026-11-14 00:00:30 UTC');
        $fixture->view(1, at: '2026-11-13 20:00 UTC');
        $rollup = $fixture->rollup();
        $rollup->run($fixture->clock->now());

        $fixture->view(1, ip: '192.0.2.77', at: '2026-11-13 23:59:59 UTC');
        $rollup->run($fixture->clock->now());

        self::assertSame([['visitors', 2], ['views', 2]], self::values($fixture->store->figures(['visitors', 'views'], '', '2026-11-13', '2026-11-13'), ['visitors', 'views']));
    }

    public function testFiguresOlderThanTheRetentionAreDeleted(): void
    {
        $fixture = new AnalyticsFixture(now: '2026-11-14 12:00 UTC');
        foreach (['2024-10-13', '2024-10-14', '2026-11-13'] as $day) {
            $fixture->store->finishDay($day, [['metric' => 'views', 'dimension' => '', 'item' => '', 'value' => 1]]);
        }

        self::assertSame(['days' => [], 'purged' => 1], $fixture->rollup()->run($fixture->clock->now()), '25 months by default');
        $fixture->retention()->change(1, 7);
        self::assertSame(['days' => [], 'purged' => 1], $fixture->rollup()->run($fixture->clock->now()));
        self::assertSame('2026-11-13', $fixture->store->firstDay());
    }

    public function testRunsOnceAnHourFromScheduleRun(): void
    {
        $fixture = new AnalyticsFixture(now: '2026-11-14 12:00 UTC');
        $fixture->view(1, at: '2026-11-13 09:00 UTC');
        $rollup = $fixture->rollup();
        $job = new AnalyticsRollupJob();
        $due = static fn (): ScheduleDue => new ScheduleDue($fixture->site, new ArrayContainer([AnalyticsRollup::class => $rollup]), $fixture->clock->now());

        self::assertNull($rollup->lastRun());
        self::assertTrue($rollup->isDue($fixture->clock->now()));
        $job($first = $due());
        self::assertSame(['Analytics: rolled up 2026-11-13.'], $first->lines());
        self::assertEquals(new DateTimeImmutable('2026-11-14 12:00 UTC'), $rollup->lastRun());

        $fixture->clock->advance(3599);
        $fixture->view(1, at: '2026-11-13 09:30 UTC');
        $job($second = $due());
        self::assertSame([], $second->lines(), 'not yet an hour');
        self::assertCount(1, $fixture->rows(AnalyticsStore::HITS));

        $fixture->clock->advance(1);
        $fixture->store->finishDay('2020-01-01', [['metric' => 'views', 'dimension' => '', 'item' => '', 'value' => 1]]);
        $fixture->store->finishDay('2020-01-02', [['metric' => 'views', 'dimension' => '', 'item' => '', 'value' => 1]]);
        $job($third = $due());
        self::assertSame(['Analytics: rolled up 2026-11-13.', 'Analytics: deleted 2 daily figures older than the retention.'], $third->lines());

        $fixture->clock->advance(3600);
        $fixture->store->finishDay('2020-01-01', [['metric' => 'views', 'dimension' => '', 'item' => '', 'value' => 1]]);
        $job($fourth = $due());
        self::assertSame(['Analytics: deleted 1 daily figure older than the retention.'], $fourth->lines());
    }

    public function testAStoredTimeThatCannotBeReadIsNoTime(): void
    {
        $fixture = new AnalyticsFixture();
        $fixture->settings->set(AnalyticsRollup::LAST_RUN, 'yesterday');

        self::assertNull($fixture->rollup()->lastRun());
        self::assertSame('2026-11-14', $fixture->rollup()->today(new DateTimeImmutable('2026-11-14 23:30 UTC')));
        self::assertSame('2026-11-15', new AnalyticsFixture('Pacific/Auckland')->rollup()->today(new DateTimeImmutable('2026-11-14 23:30 UTC')));
    }

    /**
     * @param list<array{day: string, metric: string, item: string, value: int}> $figures
     * @param list<string>                                                        $order
     *
     * @return list<array{string, int}>
     */
    private static function values(array $figures, array $order): array
    {
        $values = [];
        foreach ($order as $metric) {
            foreach ($figures as $figure) {
                if ($figure['metric'] === $metric) {
                    $values[] = [$metric, $figure['value']];
                }
            }
        }

        return $values;
    }
}
