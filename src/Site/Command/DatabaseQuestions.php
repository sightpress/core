<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use InvalidArgumentException;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * The database of a site, asked piece by piece: the engine first, then a file for SQLite or the server, port,
 * database, user and password for the others, each with a default. Someone who has the whole address (DSN) can
 * give it with `--db` instead, and then none of the pieces is asked.
 */
final readonly class DatabaseQuestions
{
    public const array ENGINES = ['sqlite', 'mysql', 'mariadb', 'postgresql'];

    private const array PORTS = ['mysql' => '3306', 'mariadb' => '3306', 'postgresql' => '5432'];

    /**
     * @param string $sitesDir where SQLite files go by default, and what a relative file path starts from
     */
    public function __construct(private string $sitesDir)
    {
    }

    /**
     * `--db` and its pieces; `$slug` reads the site's slug from the answers so far, for the default names.
     *
     * @param \Closure(CommandInput): string $slug
     *
     * @return list<CommandParameter>
     */
    public function parameters(\Closure $slug): array
    {
        $option = CommandParameterType::Option;
        $pieces = static fn (CommandInput $answers): bool => trim($answers->option('db') ?? '') === '';
        $file = static fn (CommandInput $answers): bool => $pieces($answers) && $answers->option('db-engine') === 'sqlite';
        $server = static fn (CommandInput $answers): bool => $pieces($answers) && $answers->option('db-engine') !== 'sqlite';

        return [
            new CommandParameter(
                'db',
                'Whole database address (DSN), for example postgresql://user:password@db:5432/demo; leave it out to give the pieces one by one.',
                $option,
                label: 'Database address',
                secret: true,
            ),
            new CommandParameter(
                'db-engine',
                'Which database engine does the site use? SQLite keeps everything in one file and needs no database server.',
                $option,
                true,
                label: 'Database engine',
                default: 'sqlite',
                choices: self::ENGINES,
                when: $pieces,
            ),
            new CommandParameter(
                'db-path',
                'Where is the SQLite file? A path that does not start with / is inside the sites folder.',
                $option,
                true,
                label: 'SQLite file',
                default: fn (CommandInput $answers): string => $this->sitesDir . '/' . $slug($answers) . '.sqlite',
                normalise: $this->path(...),
                when: $file,
            ),
            new CommandParameter(
                'db-host',
                'Name or address of the database server:',
                $option,
                true,
                label: 'Database server',
                default: 'localhost',
                normalise: self::host(...),
                when: $server,
            ),
            new CommandParameter(
                'db-port',
                'Port of the database server:',
                $option,
                true,
                label: 'Database port',
                default: static fn (CommandInput $answers): ?string => self::PORTS[$answers->option('db-engine') ?? ''] ?? null,
                normalise: self::port(...),
                when: $server,
            ),
            new CommandParameter(
                'db-name',
                'Name of the database:',
                $option,
                true,
                label: 'Database name',
                default: static fn (CommandInput $answers): string => 'sightpress_' . str_replace('-', '_', $slug($answers)),
                normalise: self::databaseName(...),
                when: $server,
            ),
            new CommandParameter(
                'db-user',
                'User name for the database:',
                $option,
                true,
                label: 'Database user',
                default: 'sightpress',
                when: $server,
            ),
            new CommandParameter(
                'db-password',
                'Password of that database user (leave it empty if it has none):',
                $option,
                label: 'Database password',
                when: $server,
                secret: true,
                ask: true,
            ),
        ];
    }

    /**
     * The whole address: `--db` when given, otherwise built from the pieces, with the user and password encoded.
     */
    public static function dsn(CommandInput $input): string
    {
        $dsn = trim($input->option('db') ?? '');
        if ($dsn !== '') {
            return $dsn;
        }
        $engine = $input->option('db-engine') ?? 'sqlite';
        if ($engine === 'sqlite') {
            return 'sqlite:///' . ($input->option('db-path') ?? '');
        }
        $password = $input->option('db-password') ?? '';

        return sprintf(
            '%s://%s%s@%s:%s/%s',
            $engine,
            rawurlencode($input->option('db-user') ?? ''),
            $password === '' ? '' : ':' . rawurlencode($password),
            $input->option('db-host') ?? '',
            $input->option('db-port') ?? '',
            $input->option('db-name') ?? '',
        );
    }

    private function path(string $path): string
    {
        $path = str_starts_with($path, '/') ? $path : rtrim($this->sitesDir, '/') . '/' . $path;
        if (str_ends_with($path, '/')) {
            throw new InvalidArgumentException('Give the path of a file, not of a folder, for example /srv/sites/demo.sqlite.');
        }

        return $path;
    }

    private static function host(string $host): string
    {
        $host = mb_strtolower($host);
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false && filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not a server name or address: use one such as localhost, db or 10.0.0.5.', $host));
        }

        return $host;
    }

    private static function port(string $port): string
    {
        $number = filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($number === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not a port: use a whole number from 1 to 65535, such as 5432.', $port));
        }

        return (string) $number;
    }

    private static function databaseName(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_$-]{1,63}$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a database name: use letters, digits and _, such as sightpress_demo.', $name));
        }

        return $name;
    }
}
