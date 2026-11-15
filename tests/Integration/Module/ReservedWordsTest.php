<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Module;

use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Platforms\Keywords\MariaDB117Keywords;
use Doctrine\DBAL\Platforms\Keywords\MySQL84Keywords;
use Doctrine\DBAL\Platforms\Keywords\PostgreSQLKeywords;
use Doctrine\DBAL\Platforms\Keywords\SQLiteKeywords;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\CoreSiteDatabase;

/**
 * No table or column of core's schema is a reserved word on any supported engine. The ORM doesn't quote names, so a
 * column such as `system` (reserved since MySQL 8.0) works on SQLite, PostgreSQL and MariaDB but breaks every
 * insert on MySQL.
 */
#[CoversNothing]
final class ReservedWordsTest extends TestCase
{
    public function testNoNameInTheSchemaIsReservedOnAnyEngine(): void
    {
        $database = new CoreSiteDatabase();
        $schema = $database->connection->createSchemaManager();
        /** @var array<string, KeywordList> $engines */
        $engines = [
            'MySQL' => new MySQL84Keywords(),
            'MariaDB' => new MariaDB117Keywords(),
            'PostgreSQL' => new PostgreSQLKeywords(),
            'SQLite' => new SQLiteKeywords(),
        ];

        $reserved = [];
        foreach ($database->tables() as $table) {
            $names = [$table => $table];
            foreach ($schema->introspectTableColumnsByUnquotedName($table === '' ? '?' : $table) as $column) {
                $word = $column->getObjectName()->getIdentifier()->getValue();
                $names[$table . '.' . $word] = $word;
            }
            foreach ($names as $name => $word) {
                foreach ($engines as $engine => $keywords) {
                    if ($keywords->isKeyword($word)) {
                        $reserved[] = sprintf('%s (reserved on %s)', $name, $engine);
                    }
                }
            }
        }

        self::assertSame([], $reserved);
    }
}
