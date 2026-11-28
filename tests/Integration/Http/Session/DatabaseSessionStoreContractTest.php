<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Http\Session;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use SightPress\Core\Http\Session\DatabaseSessionStore;
use SightPress\Core\Http\Session\SessionStore;
use SightPress\Core\Site\Migrations\Version20261127100000;
use SightPress\Core\Site\Migrations\Version20261128090000;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\TestDatabases;

/**
 * On SQLite in memory, and on MySQL, MariaDB and PostgreSQL in the DB matrix (SIGHTPRESS_TEST_DATABASE_URL).
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class DatabaseSessionStoreContractTest extends SessionStoreContract
{
    private Connection $connection;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->connection = TestDatabases::connection();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-27 12:00:00 UTC'));
        $schema = new Schema();
        new Version20261127100000($this->connection, new NullLogger())->up($schema);
        new Version20261128090000($this->connection, new NullLogger())->up($schema);
        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        TestDatabases::dropAll();
    }

    protected function store(int $idleTimeout = 3600): SessionStore
    {
        return new DatabaseSessionStore($this->connection, $this->clock, $idleTimeout);
    }

    protected function wait(int $seconds): void
    {
        $this->clock->advance($seconds);
    }

    protected function stored(): string
    {
        return json_encode($this->connection->createQueryBuilder()->select('*')->from(DatabaseSessionStore::TABLE)->fetchAllAssociative(), JSON_THROW_ON_ERROR);
    }

    public function testARequestThatChangedNothingOnlyWritesOnceAMinute(): void
    {
        $store = $this->store(120);
        $store->write('session-id', ['user_id' => 7]);
        $this->wait(30);
        $store->touch('session-id');
        self::assertSame('2026-11-27 12:00:00', $this->lastSeen(), 'not written again within a minute');

        $this->wait(31);
        $store->touch('session-id');
        self::assertSame('2026-11-27 12:01:01', $this->lastSeen());
        $this->wait(100);
        self::assertSame(['user_id' => 7], $store->read('session-id'), 'touching moved the expiry on');
    }

    private function lastSeen(): string
    {
        $value = $this->connection->createQueryBuilder()->select('last_seen_at')->from(DatabaseSessionStore::TABLE)->fetchOne();

        return substr(is_string($value) ? $value : '', 0, 19);
    }
}
