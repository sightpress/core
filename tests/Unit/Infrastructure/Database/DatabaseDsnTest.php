<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Database\DatabaseDsn;
use SightPress\Core\Infrastructure\Database\DatabaseDsnException;

#[CoversClass(DatabaseDsn::class)]
final class DatabaseDsnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function valid(): iterable
    {
        yield 'SQLite in memory' => ['sqlite:///:memory:', ['driver' => 'pdo_sqlite', 'memory' => true], 'SQLite in memory'];
        yield 'SQLite file' => [
            'sqlite:////srv/data/demo.sqlite',
            ['driver' => 'pdo_sqlite', 'path' => '/srv/data/demo.sqlite'],
            'SQLite file /srv/data/demo.sqlite',
        ];
        yield 'MySQL' => [
            'mysql://app:p%40ss@db.internal:3306/site_demo?charset=utf8mb4',
            ['driver' => 'pdo_mysql', 'host' => 'db.internal', 'port' => 3306, 'user' => 'app', 'password' => 'p@ss', 'dbname' => 'site_demo', 'charset' => 'utf8mb4'],
            'MySQL/MariaDB database "site_demo" on db.internal:3306',
        ];
        yield 'MariaDB' => [
            'mariadb://app:secret@mariadb/site_demo',
            ['driver' => 'pdo_mysql', 'host' => 'mariadb', 'user' => 'app', 'password' => 'secret', 'dbname' => 'site_demo'],
            'MySQL/MariaDB database "site_demo" on mariadb',
        ];
        yield 'PostgreSQL' => [
            'postgresql://app:secret@postgres:5432/site_demo?serverVersion=17',
            ['driver' => 'pdo_pgsql', 'host' => 'postgres', 'port' => 5432, 'user' => 'app', 'password' => 'secret', 'dbname' => 'site_demo', 'serverVersion' => '17'],
            'PostgreSQL database "site_demo" on postgres:5432',
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('valid')]
    public function testParsesSupportedEngines(string $dsn, array $params, string $description): void
    {
        $parsed = DatabaseDsn::parse($dsn);

        $actual = $parsed->connectionParams();
        ksort($actual);
        ksort($params);
        self::assertSame($params, $actual);
        self::assertSame($description, $parsed->describe());
        self::assertStringNotContainsString('secret', $parsed->describe());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'no scheme' => ['db.internal/site', 'must start with sqlite://, mysql://, mariadb:// or postgresql://.'];
        yield 'unsupported engine' => ['oracle://u:p@db/site', 'must start with sqlite://'];
        yield 'malformed' => ['mysql://u:p@:99999999/x', 'The database DSN is not a valid URL.'];
        yield 'SQLite without a file' => ['sqlite:///', 'The SQLite DSN must name a file'];
        yield 'no database' => ['mysql://u:secret@db.internal', 'must name a host and a database.'];
        yield 'no host' => ['postgresql:///site', 'The database DSN is not a valid URL.'];
    }

    #[DataProvider('invalid')]
    public function testRejectsWhatItCannotUseWithoutShowingIt(string $dsn, string $message): void
    {
        try {
            DatabaseDsn::parse($dsn);
            self::fail('The DSN was accepted.');
        } catch (DatabaseDsnException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }
}
