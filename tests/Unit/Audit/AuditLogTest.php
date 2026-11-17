<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Audit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditEntry;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Audit\AuditMigrations;
use SightPress\Core\Tests\Support\CoreSiteDatabase;

#[CoversClass(AuditLog::class)]
#[CoversClass(AuditEntry::class)]
#[CoversClass(AuditFilter::class)]
#[CoversClass(AuditMigrations::class)]
#[UsesNamespace('SightPress\Core')]
final class AuditLogTest extends TestCase
{
    public function testAppendsAndFindsEntriesNewestFirstWithFilters(): void
    {
        $database = new CoreSiteDatabase();
        $log = new AuditLog($database->connection);
        $log->append(new AuditEntry(new DateTimeImmutable('2026-11-16 09:00:00 UTC'), 1, 'page.saved', 'page', '4', 'Saved “About”.', ['revision' => 2]));
        $log->append(new AuditEntry(new DateTimeImmutable('2026-11-17 09:00:00 UTC'), 2, 'media.uploaded', 'media', '7', str_repeat('x', 600)));
        $log->append(new AuditEntry(new DateTimeImmutable('2026-11-17 10:00:00 UTC'), null, 'settings.changed', 'settings', null, 'Changed the site settings.'));

        $all = $log->find(new AuditFilter(), 10);
        self::assertSame(['settings.changed', 'media.uploaded', 'page.saved'], array_map(static fn (AuditEntry $e): string => $e->action, $all));
        self::assertSame([null, null, []], [$all[0]->actorId, $all[0]->entityId, $all[0]->details]);
        self::assertSame(500, mb_strlen($all[1]->summary));
        self::assertSame(['4', ['revision' => 2], '2026-11-16T09:00:00+00:00'], [$all[2]->entityId, $all[2]->details, $all[2]->occurredAt->format(DATE_ATOM)]);
        self::assertNotNull($all[2]->id);

        self::assertSame(1, $log->count(new AuditFilter(actorId: 1)));
        self::assertSame(1, $log->count(new AuditFilter(entityType: 'media')));
        self::assertSame(2, $log->count(new AuditFilter(from: new DateTimeImmutable('2026-11-17 00:00:00 UTC'))));
        self::assertSame(1, $log->count(new AuditFilter(before: new DateTimeImmutable('2026-11-17 00:00:00 UTC'))));
        self::assertSame(['media.uploaded'], array_map(static fn (AuditEntry $e): string => $e->action, $log->find(new AuditFilter(), 1, 1)));
        $actors = $log->actors();
        sort($actors);
        self::assertSame([1, 2], $actors);
        self::assertTrue(new AuditFilter()->isEmpty());
        self::assertFalse(new AuditFilter(entityType: 'page')->isEmpty());
        self::assertSame('SightPress\Core\Audit\Migrations', new AuditMigrations()->namespace());
        self::assertDirectoryExists(new AuditMigrations()->directory());
    }
}
