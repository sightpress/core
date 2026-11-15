<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\TestDatabases;

/**
 * Base class for integration tests: every test gets its own empty database, SQLite in memory unless the DB matrix
 * job names a server ({@see TestDatabases}).
 *
 * Integration tests never count towards unit coverage, so they declare #[CoversNothing].
 */
abstract class IntegrationTestCase extends TestCase
{
    private ?Connection $connection = null;

    protected function connection(): Connection
    {
        return $this->connection ??= TestDatabases::connection();
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
        $this->connection = null;
        TestDatabases::dropAll();

        parent::tearDown();
    }
}
