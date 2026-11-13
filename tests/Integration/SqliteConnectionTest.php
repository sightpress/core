<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class SqliteConnectionTest extends IntegrationTestCase
{
    public function testItOpensAnEmptySqliteDatabaseInMemory(): void
    {
        $connection = $this->connection();

        self::assertInstanceOf(SQLitePlatform::class, $connection->getDatabasePlatform());
        self::assertSame([], $connection->createSchemaManager()->listTableNames());
    }

    public function testItWritesAndReadsThroughTheSchemaApiAndQueryBuilder(): void
    {
        $connection = $this->connection();

        $table = new Table('probe');
        $table->addColumn('id', Types::INTEGER);
        $table->addColumn('label', Types::STRING, ['length' => 50]);
        $connection->createSchemaManager()->createTable($table);

        $connection->createQueryBuilder()
            ->insert('probe')
            ->values(['id' => ':id', 'label' => ':label'])
            ->setParameters(['id' => 1, 'label' => 'hello'])
            ->executeStatement();

        $label = $connection->createQueryBuilder()
            ->select('label')
            ->from('probe')
            ->where('id = :id')
            ->setParameter('id', 1)
            ->fetchOne();

        self::assertSame('hello', $label);
    }
}
