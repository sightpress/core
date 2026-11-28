<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Session;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SightPress\Core\Http\Session\DatabaseSessionStore;
use SightPress\Core\Site\Migrations\Version20261127100000;
use SightPress\Core\Tests\Support\FixedClock;

#[CoversClass(DatabaseSessionStore::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(Version20261127100000::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Infrastructure\Migration\PortableMigration::class)]
final class DatabaseSessionStoreTest extends TestCase
{
    private Connection $connection;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->clock = new FixedClock(new DateTimeImmutable('2026-11-27 12:00:00 UTC'));
        $schema = new Schema();
        new Version20261127100000($this->connection, new NullLogger())->up($schema);
        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    public function testKeepsOnlyAHashOfTheIdWithItsExpiry(): void
    {
        $this->store()->write('session-id', ['user_id' => 7]);

        self::assertSame([[
            'id_hash' => hash('sha256', 'session-id'),
            'data' => '{"user_id":7}',
            'last_seen_at' => '2026-11-27 12:00:00',
            'expires_at' => '2026-11-27 12:02:00',
        ]], $this->connection->createQueryBuilder()->select('*')->from(DatabaseSessionStore::TABLE)->fetchAllAssociative());
        self::assertSame(hash('sha256', 'session-id'), DatabaseSessionStore::hash('session-id'));
    }

    public function testAnExpiredSessionIsRefusedAndRemovedByTheSchedule(): void
    {
        $store = $this->store();
        $store->write('old', ['user_id' => 1]);
        $this->clock->advance(60);
        $store->write('new', ['user_id' => 2]);
        $this->clock->advance(60);

        self::assertNull($store->read('old'), 'it ended exactly at its expiry');
        self::assertSame(['user_id' => 2], $store->read('new'));
        self::assertSame(1, $store->forgetExpired($this->clock->now()));
        self::assertCount(1, $this->connection->createQueryBuilder()->select('id_hash')->from(DatabaseSessionStore::TABLE)->fetchFirstColumn());
    }

    public function testUpdatesTouchesOnceAMinuteAndDeletes(): void
    {
        $store = $this->store();
        $store->write('session-id', ['user_id' => 7]);
        $store->write('session-id', ['user_id' => 8]);
        self::assertSame(['user_id' => 8], $store->read('session-id'));

        $this->clock->advance(30);
        $store->touch('session-id');
        self::assertSame('2026-11-27 12:00:00', $this->lastSeen(), 'not written again within a minute');
        $this->clock->advance(31);
        $store->touch('session-id');
        self::assertSame('2026-11-27 12:01:01', $this->lastSeen());
        $this->clock->advance(100);
        self::assertSame(['user_id' => 8], $store->read('session-id'), 'touching moved the expiry on');

        $store->delete('session-id');
        self::assertNull($store->read('session-id'));
        $store->touch('session-id');
        self::assertNull($store->read('session-id'), 'touching a deleted session leaves it gone');
    }

    public function testDamagedDataIsIgnored(): void
    {
        foreach (['broken' => '{oops', 'scalar' => '42'] as $id => $data) {
            $this->connection->insert(DatabaseSessionStore::TABLE, [
                'id_hash' => DatabaseSessionStore::hash($id),
                'data' => $data,
                'last_seen_at' => '2026-11-27 12:00:00',
                'expires_at' => '2026-11-27 13:00:00',
            ]);
        }

        self::assertNull($this->store()->read('broken'));
        self::assertNull($this->store()->read('scalar'));
    }

    public function testAWriteWhoseUpdateChangedNoRowUpdatesTheRowThatIsThere(): void
    {
        // As on MySQL, where a row whose values didn't change counts as not updated: the insert then finds the row.
        $this->store()->write('session-id', ['user_id' => 7]);
        $this->connection->executeStatement('CREATE TRIGGER skip_one BEFORE UPDATE ON session WHEN NEW.data = \'{"user_id":8}\' AND OLD.data = \'{"user_id":7}\' BEGIN SELECT RAISE(IGNORE); END');

        $this->store()->write('session-id', ['user_id' => 8]);

        self::assertSame(['user_id' => 7], $this->store()->read('session-id'), 'the update the trigger skipped left the row, and no second row was made');
        self::assertCount(1, $this->connection->createQueryBuilder()->select('id_hash')->from(DatabaseSessionStore::TABLE)->fetchFirstColumn());
    }

    private function lastSeen(): string
    {
        $value = $this->connection->createQueryBuilder()->select('last_seen_at')->from(DatabaseSessionStore::TABLE)->fetchOne();

        return substr(is_string($value) ? $value : '', 0, 19);
    }

    private function store(): DatabaseSessionStore
    {
        return new DatabaseSessionStore($this->connection, $this->clock, 120);
    }
}
