<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditEntry;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Audit\ModuleAuditTrail;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Module\Audit\AuditRecord;

#[CoversClass(ModuleAuditTrail::class)]
#[UsesNamespace('SightPress\Core')]
final class ModuleAuditTrailTest extends TestCase
{
    public function testAModuleRecordBecomesAnAuditLogLine(): void
    {
        $log = new AuditLog(new CoreSiteDatabase()->connection);
        $trail = new ModuleAuditTrail($log, new FixedClock());

        $trail->record(new AuditRecord('acme.events.event_added', 'Added the event “Open day”.', 4, 'event', 12, ['tickets' => 30]));
        $trail->record(new AuditRecord('acme.events.reminders_sent', 'Sent 3 reminders.'));

        $entries = $log->find(new AuditFilter(), 10);
        self::assertSame(
            [['acme.events.reminders_sent', null, 'module', null, 'Sent 3 reminders.', []], ['acme.events.event_added', 4, 'event', '12', 'Added the event “Open day”.', ['tickets' => 30]]],
            array_map(static fn (AuditEntry $e): array => [$e->action, $e->actorId, $e->entityType, $e->entityId, $e->summary, $e->details], $entries),
        );
        self::assertSame('2026-11-14 12:00', $entries[0]->occurredAt->format('Y-m-d H:i'));
    }
}
