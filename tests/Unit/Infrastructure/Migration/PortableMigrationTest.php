<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

#[CoversClass(PortableMigration::class)]
final class PortableMigrationTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function engines(): iterable
    {
        yield 'SQLite' => [['driver' => 'pdo_sqlite', 'memory' => 'true'], true];
        yield 'PostgreSQL' => [['driver' => 'pdo_pgsql', 'serverVersion' => '18.0'], true];
        yield 'MySQL' => [['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0'], false];
        yield 'MariaDB' => [['driver' => 'pdo_mysql', 'serverVersion' => '11.8.0-MariaDB'], false];
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('engines')]
    public function testRunsInATransactionOnlyWhereSchemaChangesCanBeUndone(array $params, bool $transactional): void
    {
        /** @phpstan-ignore argument.type (connection parameters for a platform only; nothing connects) */
        $connection = DriverManager::getConnection($params);
        $migration = new class ($connection, new NullLogger()) extends PortableMigration {
            public function up(Schema $schema): void
            {
            }
        };

        self::assertSame($transactional, $migration->isTransactional());
    }
}
