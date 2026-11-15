<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Crypto\SecretBoxException;
use SightPress\Core\Infrastructure\Database\DatabaseDsn;
use SightPress\Core\Infrastructure\Database\DatabaseDsnException;
use SightPress\Core\Infrastructure\Database\DatabaseProvisioner;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteMigrator;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;
use Throwable;

/**
 * `site:db:change <slug> --db= [--migrate]`: points a disabled site at another database, for example after the
 * operator copied its data to a new server. Nothing is copied. The new database must exist and be up to date with
 * the installed modules (or brought up to date with `--migrate`); only then is its address stored, encrypted, and
 * the change written to the `sites` log, naming engine, host and database only.
 */
final readonly class ChangeSiteDatabaseCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SecretBox $secretBox,
        private DatabaseProvisioner $databases,
        private SiteMigrator $migrator,
        private SiteContainerFactory $containers,
        private LoggerFactory $loggers,
    ) {
    }

    public function name(): string
    {
        return 'site:db:change';
    }

    public function description(): string
    {
        return 'Points a disabled site at another database. Nothing is copied: move the data first.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the disabled site:', required: true),
            new CommandParameter('db', 'Address (DSN) of the new database, for example postgresql://user:password@db:5432/demo:', CommandParameterType::Option, true),
            new CommandParameter('migrate', 'Run the migrations the new database doesn\'t have yet.', CommandParameterType::Flag),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = $input->argument('slug') ?? '';
        $site = $this->sites->find($slug);
        if ($site === null) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }
        if ($site->status !== SiteStatus::Disabled) {
            $output->error(sprintf(
                'The site "%s" is %s. Only a disabled site can change its database: run site:disable %s first.',
                $slug,
                $site->status->value,
                $slug,
            ));

            return self::FAILURE;
        }
        if (!$this->secretBox->isConfigured()) {
            $output->error('APP_SECRET is not set: set it in the environment before you change a site\'s database.');

            return self::INVALID;
        }

        $plainDsn = trim($input->option('db') ?? '');
        try {
            $dsn = DatabaseDsn::parse($plainDsn);
        } catch (DatabaseDsnException $exception) {
            $output->error($exception->getMessage());

            return self::INVALID;
        }

        $current = $this->current($site);
        $before = $current === null ? 'old database' : DatabaseDsn::parse($current)->describe();
        if ($current === $plainDsn) {
            $output->line(sprintf('The site "%s" already uses the %s.', $slug, $before));

            return self::SUCCESS;
        }

        try {
            $this->databases->prepare($dsn);
        } catch (DatabaseDsnException $exception) {
            $output->error($exception->getMessage() . sprintf(' The site still uses the %s.', $before));

            return self::FAILURE;
        }

        $moved = $site->withDatabaseDsn($this->secretBox->encrypt($plainDsn));
        try {
            $problem = $this->checkMigrations($moved, $dsn, $input->flag('migrate'), $output);
        } catch (Throwable $exception) {
            $problem = sprintf('The migrations of the %s could not be checked or run: %s', $dsn->describe(), rtrim($exception->getMessage(), '.') . '.');
        } finally {
            $this->containers->evict($slug);
        }
        if ($problem !== null) {
            $output->error($problem . sprintf(' The site still uses the %s.', $before));

            return self::FAILURE;
        }

        $this->sites->save($moved);
        $this->containers->evict($slug);
        $this->loggers->create('sites')->notice(
            sprintf('Site "%s" database changed from %s to %s.', $slug, $before, $dsn->describe()),
            ['site' => $slug, 'from' => $before, 'to' => $dsn->describe()],
        );
        $output->line(sprintf(
            'The site "%s" now uses the %s. Nothing was copied from the %s. Run site:enable %s when you are ready.',
            $slug,
            $dsn->describe(),
            $before,
            $slug,
        ));

        return self::SUCCESS;
    }

    /**
     * The site's current database address, or null when this APP_SECRET can't read it.
     */
    private function current(Site $site): ?string
    {
        try {
            $plain = $this->secretBox->decrypt($site->databaseDsn);
            DatabaseDsn::parse($plain);

            return $plain;
        } catch (SecretBoxException | DatabaseDsnException) {
            return null;
        }
    }

    /**
     * Makes sure the new database is up to date with the installed modules, running the missing migrations when
     * asked to.
     *
     * @return string|null why the site can't use the database, or null when it can
     */
    private function checkMigrations(Site $moved, DatabaseDsn $dsn, bool $migrate, CommandOutput $output): ?string
    {
        $unknown = $this->migrator->unknown($moved);
        if ($unknown > 0) {
            return sprintf(
                'The %s has %s that no installed module has: it belongs to a newer version of SightPress or to a module that isn\'t installed.',
                $dsn->describe(),
                self::migrations($unknown),
            );
        }

        $pending = $this->migrator->pending($moved);
        if ($pending === 0) {
            return null;
        }
        if (!$migrate) {
            return sprintf(
                'The %s is %s behind. Run the command again with --migrate to run them, or use an up-to-date database.',
                $dsn->describe(),
                self::migrations($pending),
            );
        }

        $output->line(sprintf('Ran %s.', self::migrations(count($this->migrator->migrate($moved)))));

        return null;
    }

    private static function migrations(int $count): string
    {
        return $count === 1 ? '1 database migration' : sprintf('%d database migrations', $count);
    }
}
