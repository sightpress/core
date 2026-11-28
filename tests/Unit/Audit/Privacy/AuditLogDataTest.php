<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Audit\Privacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Privacy\Row;
use SightPress\Core\Audit\Privacy\AuditLogData;
use SightPress\Core\Tests\Support\Privacy\SiteTables;
use SightPress\Module\Privacy\ErasureRule;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Sms\PhoneNumber;

#[CoversClass(AuditLogData::class)]
#[UsesNamespace('SightPress\Core')]
final class AuditLogDataTest extends TestCase
{
    public function testExportsThePersonsEntriesAndPseudonymisesThem(): void
    {
        $connection = SiteTables::connection();
        SiteTables::user($connection, 7, 'ada@example.org', ['first_name' => 'Ana', 'last_name' => 'Lovelace', 'display_name' => 'Countess']);
        $rows = [
            [1, 7, 'page.saved', 'page', '3', 'Ana Lovelace saved the page “Home”.', '{"by":"ada@example.org"}'],
            [2, 1, 'user.role_changed', 'user', '7', 'Changed the role of Countess from editor to admin.', null],
            [3, 1, 'page.saved', 'page', '4', 'Grace saw Analytics with Ana.', '{"phone":"+447700900123"}'],
            [4, 1, 'page.saved', 'page', '5', 'Nothing about her.', null],
        ];
        foreach ($rows as [$id, $actor, $action, $type, $entity, $summary, $details]) {
            $connection->insert('audit_log', ['id' => $id, 'occurred_at' => '2026-11-0' . $id . ' 10:00:00', 'actor_id' => $actor, 'event_action' => $action, 'entity_type' => $type, 'entity_id' => $entity, 'summary' => $summary, 'details' => $details]);
        }
        $data = new AuditLogData($connection);
        $ada = new UserRef(7, 'ada@example.org', new PhoneNumber('+447700900123'));

        self::assertSame(ErasureRule::Pseudonymise, $data->describe()->erasure);
        self::assertSame([
            ['occurred_at' => '2026-11-01T10:00:00Z', 'by_you' => true, 'action' => 'page.saved', 'summary' => 'Ana Lovelace saved the page “Home”.'],
            ['occurred_at' => '2026-11-02T10:00:00Z', 'by_you' => false, 'action' => 'user.role_changed', 'summary' => 'Changed the role of Countess from editor to admin.'],
        ], iterator_to_array($data->export($ada), false));

        self::assertSame(3, $data->erase($ada)->pseudonymised);
        self::assertSame([
            ['[erased] saved the page “Home”.', '{"by":"[erased]"}'],
            ['Changed the role of [erased] from editor to admin.', null],
            ['Grace saw Analytics with [erased].', '{"phone":"[erased]"}'],
            ['Nothing about her.', null],
        ], array_map(static fn (array $row): array => [$row['summary'], $row['details']], $connection->fetchAllAssociative('SELECT summary, details FROM audit_log ORDER BY id')));
        self::assertSame(7, Row::int($connection->fetchOne('SELECT actor_id FROM audit_log WHERE id = 1')), 'the history stays, on the tombstone');
        self::assertSame(0, $data->erase($ada)->pseudonymised, 'erasing again changes nothing');
        self::assertSame(0, new AuditLogData($connection)->erase(new UserRef(99, 'x@example.org'))->pseudonymised);
    }
}
