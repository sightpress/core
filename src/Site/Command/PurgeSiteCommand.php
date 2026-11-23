<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use League\Flysystem\FilesystemException;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Crypto\SecretBoxException;
use SightPress\Core\Infrastructure\Database\DatabaseDsn;
use SightPress\Core\Infrastructure\Database\DatabaseDsnException;
use SightPress\Core\Infrastructure\Database\DatabaseProvisioner;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Infrastructure\Storage\SiteFilesystems;
use SightPress\Core\Infrastructure\Storage\StorageException;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:purge <slug>`: removes a deleted site for good: its entry in the registry, its files in the default local
 * folder and under its bucket prefix. A custom `storage.path` belongs to the operator and is left alone. The database
 * is kept unless `--drop-database` is given. Only a site that was deleted with `site:delete` can be purged. If the
 * files can't be removed the site stays in the registry, so the command can be run again.
 */
final readonly class PurgeSiteCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SecretBox $secretBox,
        private DatabaseProvisioner $databases,
        private SiteContainerFactory $containers,
        private LoggerFactory $loggers,
        private SiteFilesystems $filesystems,
        private TenantConfig $config,
    ) {
    }

    public function name(): string
    {
        return 'site:purge';
    }

    public function description(): string
    {
        return 'Removes a deleted site and its files for good. Its database is kept unless you add --drop-database.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the deleted site:', required: true),
            new CommandParameter('drop-database', 'Also delete the site\'s database. This can\'t be undone.', CommandParameterType::Flag),
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
        if ($site->status !== SiteStatus::Deleted) {
            $output->error(sprintf(
                'The site "%s" is %s. Only deleted sites can be purged: run site:delete %s first.',
                $slug,
                $site->status->value,
                $slug,
            ));

            return self::FAILURE;
        }

        $drop = $input->flag('drop-database');
        $database = $this->database($site->databaseDsn);
        if ($drop && $database === null) {
            $output->error('The database address can\'t be read with this APP_SECRET, so the database can\'t be deleted. The site was not purged.');

            return self::FAILURE;
        }
        $keepFolder = $site->storage->path !== null || !$this->folderIsFreeOfDatabase($site, $database);
        try {
            $this->removeFiles($site, $keepFolder);
        } catch (StorageException | FilesystemException $exception) {
            $output->error(($exception instanceof StorageException ? $exception->getMessage() : 'The site\'s files can\'t be deleted.') . ' The site was not purged.');

            return self::FAILURE;
        }
        if ($drop && $database !== null) {
            try {
                $this->databases->drop($database);
            } catch (DatabaseDsnException $exception) {
                $output->error($exception->getMessage() . ' The site was not purged.');

                return self::FAILURE;
            }
        }

        $this->sites->remove($slug);
        $this->containers->evict($slug);
        $this->loggers->create('sites')->notice(sprintf('Site "%s" purged.', $slug), [
            'site' => $slug,
            'database_dropped' => $drop,
        ]);
        $output->line(sprintf('The site "%s" and its files were removed.', $slug));
        if ($keepFolder) {
            $output->line(sprintf(
                'Its folder %s was kept: delete it yourself when you no longer need it.',
                $site->storage->root($this->config->sitesDir, $site->id),
            ));
        }
        $described = $database?->describe() ?? 'database';
        $output->line($drop
            ? sprintf('Deleted its %s.', $described)
            : sprintf('Its %s was kept: delete it yourself when you no longer need it.', $described));

        return self::SUCCESS;
    }

    private function removeFiles(Site $site, bool $keepFolder): void
    {
        $s3 = $site->storage->s3;
        if ($s3 !== null) {
            foreach ($this->sites->all() as $other) {
                if ($other->slug !== $site->slug && $other->storage->s3?->overlaps($s3) === true) {
                    throw new StorageException(sprintf('The site "%s" uses the same folder of the bucket.', $other->slug));
                }
            }
            $this->filesystems->bucket($s3)->deleteDirectory('');
        }
        if (!$keepFolder) {
            $this->filesystems->local($site)->deleteDirectory('');
        }
    }

    /**
     * A SQLite database can sit inside the site's folder. When its address can't be read, assume it might.
     */
    private function folderIsFreeOfDatabase(Site $site, ?DatabaseDsn $database): bool
    {
        if ($database === null) {
            return false;
        }
        $path = $database->connectionParams()['path'] ?? null;

        return !is_string($path) || !str_starts_with($path, rtrim($site->storage->root($this->config->sitesDir, $site->id), '/') . '/');
    }

    private function database(string $encrypted): ?DatabaseDsn
    {
        try {
            return DatabaseDsn::parse($this->secretBox->decrypt($encrypted));
        } catch (SecretBoxException | DatabaseDsnException) {
            return null;
        }
    }
}
