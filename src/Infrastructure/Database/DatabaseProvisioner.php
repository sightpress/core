<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Database;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Gets a site's database ready, or explains in a sentence why it can't, without ever showing a user name or a
 * password: errors name only the engine, host and database ({@see DatabaseDsn::describe()}).
 *
 * - SQLite: the file (and its folder) is created when missing.
 * - MySQL, MariaDB, PostgreSQL: the database must exist, unless creating it is allowed; then it is created with the
 *   schema API (no SQL is written by hand), which needs a user allowed to create databases.
 *
 * @phpstan-import-type Params from DriverManager
 */
final readonly class DatabaseProvisioner
{
    /** @var Closure(Params): Connection */
    private Closure $connect;

    /**
     * @param (Closure(Params): Connection)|null $connect opens a connection; DBAL's driver manager when null
     */
    public function __construct(?Closure $connect = null)
    {
        $this->connect = $connect ?? DriverManager::getConnection(...);
    }

    /**
     * Checks that the database can be used, creating it first when that is allowed.
     *
     * @throws DatabaseDsnException with a message for the person running the command
     */
    public function prepare(DatabaseDsn $dsn, bool $create = false): void
    {
        $params = $dsn->connectionParams();
        if (isset($params['memory'])) {
            return;
        }
        if (($params['driver'] ?? null) === 'pdo_sqlite') {
            $this->prepareSqliteFolder($dsn, (string) ($params['path'] ?? ''));
        }

        try {
            $this->check($params);

            return;
        } catch (DbalException) {
            if (($params['driver'] ?? null) === 'pdo_sqlite') {
                throw new DatabaseDsnException(sprintf('The %s can\'t be opened: check that the server may write to it.', $dsn->describe()));
            }
        }

        $database = (string) ($params['dbname'] ?? '');
        $server = $this->server($dsn);
        try {
            $exists = in_array($database, $server->createSchemaManager()->listDatabases(), true);
            if ($exists) {
                throw new DatabaseDsnException(sprintf(
                    'The %s exists, but this user can\'t open it: check the user\'s rights on it.',
                    $dsn->describe(),
                ));
            }
            if (!$create) {
                throw new DatabaseDsnException(sprintf(
                    'The %s does not exist. Create it, or run the command again with --create-db.',
                    $dsn->describe(),
                ));
            }
            $server->createSchemaManager()->createDatabase($database);
        } catch (DbalException) {
            throw new DatabaseDsnException(sprintf(
                'The %s could not be checked or created: check that the user may create databases.',
                $dsn->describe(),
            ));
        } finally {
            $server->close();
        }

        try {
            $this->check($params);
        } catch (DbalException) {
            throw new DatabaseDsnException(sprintf('The %s was created, but this user can\'t open it.', $dsn->describe()));
        }
    }

    /**
     * Deletes the database: the SQLite file, or the database on its server.
     *
     * @throws DatabaseDsnException with a message for the person running the command
     */
    public function drop(DatabaseDsn $dsn): void
    {
        $params = $dsn->connectionParams();
        if (isset($params['memory'])) {
            return;
        }
        if (($params['driver'] ?? null) === 'pdo_sqlite') {
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                $file = (string) ($params['path'] ?? '') . $suffix;
                if (is_file($file) && !unlink($file)) {
                    throw new DatabaseDsnException(sprintf('The %s could not be deleted.', $dsn->describe()));
                }
            }

            return;
        }

        $server = $this->server($dsn);
        try {
            $server->createSchemaManager()->dropDatabase((string) ($params['dbname'] ?? ''));
        } catch (DbalException) {
            throw new DatabaseDsnException(sprintf(
                'The %s could not be deleted: check that it exists and that the user may delete it.',
                $dsn->describe(),
            ));
        } finally {
            $server->close();
        }
    }

    /**
     * @param Params $params
     *
     * @throws DbalException when the database can't be opened
     */
    private function check(array $params): void
    {
        $connection = ($this->connect)($params);
        try {
            $connection->getServerVersion();
        } finally {
            $connection->close();
        }
    }

    /**
     * A connection to the database server itself, not to the site's database.
     */
    private function server(DatabaseDsn $dsn): Connection
    {
        $params = $dsn->connectionParams();
        unset($params['dbname']);
        if (($params['driver'] ?? null) === 'pdo_pgsql') {
            $params['dbname'] = 'postgres';
        }

        try {
            $server = ($this->connect)($params);
            $server->getServerVersion();

            return $server;
        } catch (DbalException) {
            throw new DatabaseDsnException(sprintf(
                'Can\'t connect to the database server of the %s: check the host, the port, the user name and the password.',
                $dsn->describe(),
            ));
        }
    }

    private function prepareSqliteFolder(DatabaseDsn $dsn, string $path): void
    {
        $folder = dirname($path);
        if (!is_dir($folder) && !@mkdir($folder, 0o770, true) && !is_dir($folder)) {
            throw new DatabaseDsnException(sprintf('The folder of the %s can\'t be created.', $dsn->describe()));
        }
    }
}
