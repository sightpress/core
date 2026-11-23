<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use League\Flysystem\FilesystemException;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Infrastructure\Storage\SiteFilesystems;
use SightPress\Core\Infrastructure\Storage\StorageException;
use SightPress\Core\Infrastructure\Storage\StorageMigrator;
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:storage:migrate <slug> [--delete-source]`: copies a site's local files to its bucket, checks every checksum,
 * and only then switches the site to the bucket. Running it again continues an interrupted copy and picks up files
 * added meanwhile. Local files are kept unless `--delete-source` is given, and then only ones verified in the bucket.
 */
final readonly class SiteStorageMigrateCommand implements Command
{
    public const string SETTING = 'storage.adapter';

    public function __construct(
        private SiteRepository $sites,
        private SiteFilesystems $filesystems,
        private StorageMigrator $migrator,
        private SiteContainerFactory $containers,
        private LoggerFactory $loggers,
    ) {
    }

    public function name(): string
    {
        return 'site:storage:migrate';
    }

    public function description(): string
    {
        return 'Copies a site\'s files to its bucket, checks them, then switches the site over. Local copies are kept unless you add --delete-source.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('delete-source', 'After every file is checked, delete the local copies. This can\'t be undone.', CommandParameterType::Flag),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = trim((string) $input->argument('slug'));
        $site = $this->sites->find($slug);
        if ($site === null || $site->status === SiteStatus::Deleted) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }
        $s3 = $site->storage->s3;
        if ($s3 === null) {
            $output->error(sprintf('The site "%s" has no bucket yet. Connect one with site:storage:s3 %s.', $slug, $slug));

            return self::FAILURE;
        }

        try {
            $this->filesystems->check($s3);
            $source = $this->filesystems->local($site);
            $result = $this->migrator->migrate($source, $this->filesystems->bucket($s3));
        } catch (StorageException | FilesystemException $exception) {
            $output->error(($exception instanceof StorageException ? $exception->getMessage() : 'The site\'s local files can\'t be read.') . ' The site was not switched.');

            return self::FAILURE;
        }
        $output->line(sprintf('Copied and checked: %d. Already in the bucket: %d. Failed: %d.', $result->copied, $result->alreadyThere, count($result->failed)));
        if (!$result->isComplete()) {
            foreach ($result->failed as $path) {
                $output->error('Not copied: ' . $path);
            }
            $output->error('The site was not switched and still uses its local files. Run this command again to continue.');

            return self::FAILURE;
        }

        if (!$site->storage->isCloud()) {
            $changed = $site->withStorage($site->storage->switchedToS3());
            $this->sites->save($changed);
            $this->containers->evict($slug);
            Services::get($this->containers->forSite($changed), EventDispatcherInterface::class)->dispatch(new SettingsChanged([self::SETTING], null));
            $this->loggers->create('sites')->notice(sprintf('Site "%s" moved to its bucket.', $slug), ['site' => $slug, 'files' => count($result->verified)]);
            $output->line(sprintf('The site "%s" now uses the bucket "%s".', $slug, $s3->bucket));
        }

        if (!$input->flag('delete-source')) {
            $output->line('The local copies were kept. If files were uploaded during the copy, run this command again to bring them over.');

            return self::SUCCESS;
        }
        try {
            foreach ($result->verified as $path) {
                $source->delete($path);
            }
        } catch (FilesystemException) {
            $output->error('Some local copies could not be deleted. The site is using the bucket; run this command again to finish.');

            return self::FAILURE;
        }
        $output->line(sprintf('Deleted %d local copies.', count($result->verified)));

        return self::SUCCESS;
    }
}
