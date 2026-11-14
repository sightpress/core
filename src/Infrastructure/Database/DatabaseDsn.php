<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\MalformedDsnException;
use Doctrine\DBAL\Tools\DsnParser;
use SensitiveParameter;

/**
 * A site's database address as a URL, for example `mysql://user:password@db.internal:3306/site_demo`,
 * `postgresql://…`, `sqlite:////srv/data/demo.sqlite` or `sqlite:///:memory:`.
 *
 * Only engines Doctrine DBAL supports for SightPress are accepted: SQLite, MySQL, MariaDB and PostgreSQL. Use
 * {@see describe()} in logs and messages: it names the engine, host and database, never the user or password.
 *
 * @phpstan-import-type Params from DriverManager
 */
final readonly class DatabaseDsn
{
    private const array DRIVERS = [
        'sqlite' => 'pdo_sqlite',
        'sqlite3' => 'pdo_sqlite',
        'mysql' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
        'postgresql' => 'pdo_pgsql',
        'postgres' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
    ];

    /**
     * @param Params $params
     */
    private function __construct(#[SensitiveParameter] private array $params)
    {
    }

    public static function parse(#[SensitiveParameter] string $dsn): self
    {
        $scheme = strtolower((string) strstr($dsn, '://', true));
        if (!isset(self::DRIVERS[$scheme])) {
            throw new DatabaseDsnException(
                'The database DSN must start with sqlite://, mysql://, mariadb:// or postgresql://.',
            );
        }

        try {
            $params = new DsnParser(self::DRIVERS)->parse($dsn);
        } catch (MalformedDsnException) {
            throw new DatabaseDsnException('The database DSN is not a valid URL.');
        }

        $sqlite = ($params['driver'] ?? null) === 'pdo_sqlite';
        if ($sqlite) {
            // The parser fills in a dummy host for sqlite:///…; SQLite has none.
            unset($params['host']);
        }
        if ($sqlite && !isset($params['memory']) && ($params['path'] ?? '') === '') {
            throw new DatabaseDsnException('The SQLite DSN must name a file, for example sqlite:////srv/data/site.sqlite.');
        }
        if (!$sqlite && (($params['host'] ?? '') === '' || ($params['dbname'] ?? '') === '')) {
            throw new DatabaseDsnException('The database DSN must name a host and a database.');
        }

        return new self($params);
    }

    /**
     * What DBAL needs to connect, credentials included. Never log it.
     *
     * @return Params
     */
    public function connectionParams(): array
    {
        return $this->params;
    }

    /**
     * Engine, host and database, without credentials: safe for logs and CLI output.
     */
    public function describe(): string
    {
        return match (true) {
            isset($this->params['memory']) => 'SQLite in memory',
            ($this->params['driver'] ?? null) === 'pdo_sqlite' => sprintf('SQLite file %s', $this->params['path'] ?? ''),
            default => sprintf(
                '%s database "%s" on %s%s',
                ($this->params['driver'] ?? null) === 'pdo_pgsql' ? 'PostgreSQL' : 'MySQL/MariaDB',
                $this->params['dbname'] ?? '',
                $this->params['host'] ?? '',
                isset($this->params['port']) ? ':' . $this->params['port'] : '',
            ),
        };
    }
}
