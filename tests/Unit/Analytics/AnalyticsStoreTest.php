<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\AnalyticsStore;
use SightPress\Core\Analytics\Hit;
use SightPress\Core\Tests\Support\Analytics\AnalyticsFixture;
use SightPress\Core\Tests\Support\Analytics\RacingConnection;

#[CoversClass(AnalyticsStore::class)]
#[CoversClass(Hit::class)]
#[UsesNamespace('SightPress\Core')]
final class AnalyticsStoreTest extends TestCase
{
    public function testADaysSaltIsMadeOnceAndKeptUntilTheDayIsFinished(): void
    {
        $fixture = new AnalyticsFixture();
        $store = $fixture->store;

        $salt = $store->salt('2026-11-14');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $salt);
        self::assertSame($salt, $store->salt('2026-11-14'));
        self::assertNotSame($salt, $store->salt('2026-11-15'));

        $store->record(new Hit('2026-11-13', new DateTimeImmutable('2026-11-13 10:00 UTC'), Hit::NOT_FOUND, path: '/old'));
        self::assertSame(['2026-11-13', '2026-11-14'], $store->unfinishedDaysBefore('2026-11-15'));
        self::assertSame([['kind' => 'not_found', 'path' => '/old']], array_map(
            static fn (array $row): array => array_filter($row, static fn (mixed $value): bool => $value !== null),
            [...$store->hits('2026-11-13')],
        ));

        $store->finishDay('2026-11-14', [['metric' => 'views', 'dimension' => '', 'item' => '', 'value' => 3]]);
        self::assertSame(['2026-11-13'], $store->unfinishedDaysBefore('2026-11-15'));
        self::assertSame([['metric' => 'views', 'dimension' => '', 'item' => '', 'value' => 3]], $store->dayFigures('2026-11-14'));
        self::assertNotSame($salt, $store->salt('2026-11-14'), 'a new salt after the day was finished');
    }

    public function testTwoRequestsMakingTheSaltAtOnceShareTheStoredOne(): void
    {
        $fixture = new AnalyticsFixture();
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => RacingConnection::class]);
        foreach ($fixture->database->connection->createSchemaManager()->introspectSchema()->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }
        \assert($connection instanceof RacingConnection);
        $connection->competitor = ['day_on' => '2026-11-14', 'salt' => str_repeat('a', 64)];

        self::assertSame(str_repeat('a', 64), new AnalyticsStore($connection)->salt('2026-11-14'));
    }

    public function testFiguresAreReadPerMetricAndDimensionAndDeletedByDay(): void
    {
        $store = new AnalyticsFixture()->store;
        self::assertNull($store->firstDay());
        $store->record(new Hit('2026-11-14', new DateTimeImmutable('2026-11-14 10:00 UTC'), Hit::NOT_FOUND, path: '/x'));
        self::assertSame('2026-11-14', $store->firstDay());
        foreach (['2026-10-01', '2026-11-01', '2026-11-02'] as $day) {
            $store->finishDay($day, [
                ['metric' => 'views', 'dimension' => '', 'item' => '', 'value' => 2],
                ['metric' => 'views', 'dimension' => 'page', 'item' => '1:en-GB', 'value' => 2],
                ['metric' => 'visitors', 'dimension' => '', 'item' => '', 'value' => 1],
            ]);
        }
        self::assertSame('2026-10-01', $store->firstDay());

        self::assertSame(
            [['day' => '2026-11-01', 'metric' => 'views', 'item' => '', 'value' => 2], ['day' => '2026-11-01', 'metric' => 'visitors', 'item' => '', 'value' => 1]],
            $store->figures(['views', 'visitors'], '', '2026-11-01', '2026-11-01'),
        );
        self::assertCount(2, $store->figures(['views'], 'page', '2026-11-01', '2026-11-30'));

        self::assertSame(3, $store->deleteBefore('2026-11-01'));
        self::assertSame(0, $store->deleteBefore('2026-11-01'));
        self::assertSame('2026-11-01', $store->firstDay());

        $store->salt('2026-11-14');
        $store->deleteAll();
        self::assertNull($store->firstDay());
        self::assertSame([], $store->unfinishedDaysBefore('2026-12-01'));
    }
}
