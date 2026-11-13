<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests: every test gets its own empty SQLite database in memory.
 *
 * Integration tests never count towards unit coverage, so they declare #[CoversNothing].
 */
abstract class IntegrationTestCase extends TestCase
{
    private ?Connection $connection = null;

    protected function connection(): Connection
    {
        return $this->connection ??= DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
        $this->connection = null;

        parent::tearDown();
    }
}
