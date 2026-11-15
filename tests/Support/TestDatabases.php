<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use SightPress\Core\Infrastructure\Database\DatabaseDsn;

/**
 * The databases integration tests run on: SQLite by default, or the MySQL, MariaDB or PostgreSQL server named by
 * `SIGHTPRESS_TEST_DATABASE_URL` (the DB matrix job, C-2.5), for example
 * `postgresql://sightpress:sightpress@127.0.0.1:5432/sightpress?serverVersion=18`. The URL's own database is only
 * used to reach the server; its user must be allowed to create and drop databases.
 *
 * On a server every test database is a new `sp_test_*` database, dropped again by {@see dropAll()}. One that
 * can't be dropped yet (PostgreSQL refuses while a connection is open) is tried again when the run ends
 * ({@see TestDatabaseCleanup}), and else dropped by the next run, so don't run two matrix runs against the same
 * server at the same time.
 */
final class TestDatabases
{
    public const string ENVIRONMENT = 'SIGHTPRESS_TEST_DATABASE_URL';

    private const string PREFIX = 'sp_test_';

    /** @var list<string> server databases this run created */
    private static array $created = [];

    /** @var list<string> server databases that were still in use when their test ended */
    private static array $leftovers = [];

    private static bool $swept = false;

    /**
     * `sqlite`, `mysql` (MySQL and MariaDB) or `postgresql`.
     */
    public static function engine(): string
    {
        $server = self::server();
        if ($server === null) {
            return 'sqlite';
        }

        return match (DatabaseDsn::parse($server)->connectionParams()['driver'] ?? null) {
            'pdo_pgsql' => 'postgresql',
            default => 'mysql',
        };
    }

    /**
     * The DSN of a new, empty database: on SQLite the file `<folder>/<name>.sqlite` (not created), on a server a new
     * database (created unless `$create` is false, for code that creates it itself; dropped by {@see dropAll()} either
     * way). Each call on a server gives another database, so keep the DSN if you need it twice.
     */
    public static function dsn(string $name, string $folder, bool $create = true): string
    {
        $server = self::server();
        if ($server === null) {
            return 'sqlite:///' . $folder . '/' . $name . '.sqlite';
        }

        $database = self::PREFIX . preg_replace('/[^a-z0-9]+/', '_', strtolower($name)) . '_' . bin2hex(random_bytes(4));
        self::$created[] = $database;
        if ($create) {
            self::withServer(static function (Connection $connection) use ($database): void {
                $connection->createSchemaManager()->createDatabase($database);
            });
        }

        $url = parse_url($server);
        assert(is_array($url));

        return sprintf(
            '%s://%s%s@%s%s/%s%s',
            $url['scheme'] ?? '',
            $url['user'] ?? '',
            isset($url['pass']) ? ':' . $url['pass'] : '',
            $url['host'] ?? '',
            isset($url['port']) ? ':' . $url['port'] : '',
            $database,
            isset($url['query']) ? '?' . $url['query'] : '',
        );
    }

    /**
     * A connection to a new, empty database: SQLite in memory, or a new server database.
     */
    public static function connection(): Connection
    {
        if (self::server() === null) {
            return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        }

        return DriverManager::getConnection(DatabaseDsn::parse(self::dsn('case', sys_get_temp_dir()))->connectionParams());
    }

    /**
     * Drops the server databases this run created. Close their connections first.
     */
    public static function dropAll(): void
    {
        if (self::$created === []) {
            return;
        }
        $databases = self::$created;
        self::$created = [];
        self::withServer(static function (Connection $connection) use ($databases): void {
            $schema = $connection->createSchemaManager();
            $existing = $schema->listDatabases();
            foreach (array_intersect($databases, $existing) as $database) {
                try {
                    $schema->dropDatabase($database);
                } catch (DbalException) {
                    // Still in use: tried again when the run ends ({@see dropLeftovers()}).
                    self::$leftovers[] = $database;
                }
            }
        });
    }

    /**
     * Drops the databases that were still in use when their test ended, once the run is over and the tests'
     * connections are gone. What still can't be dropped is dropped by the next run.
     */
    public static function dropLeftovers(): void
    {
        if (self::$leftovers === []) {
            return;
        }
        gc_collect_cycles();
        self::$created = self::$leftovers;
        self::$leftovers = [];
        self::dropAll();
        self::$leftovers = [];
    }

    private static function server(): ?string
    {
        $url = getenv(self::ENVIRONMENT);

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @param callable(Connection): void $work
     */
    private static function withServer(callable $work): void
    {
        $connection = DriverManager::getConnection(DatabaseDsn::parse((string) self::server())->connectionParams());
        try {
            if (!self::$swept) {
                self::$swept = true;
                $schema = $connection->createSchemaManager();
                foreach ($schema->listDatabases() as $database) {
                    if (str_starts_with($database, self::PREFIX) && !in_array($database, self::$created, true)) {
                        try {
                            $schema->dropDatabase($database);
                        } catch (DbalException) {
                            // Left by a run that is still going.
                        }
                    }
                }
            }
            $work($connection);
        } finally {
            $connection->close();
        }
    }
}
