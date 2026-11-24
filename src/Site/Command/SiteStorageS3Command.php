<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Infrastructure\Storage\SiteFilesystems;
use SightPress\Core\Infrastructure\Storage\StorageException;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRegistryException;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteS3Storage;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:storage:s3 <slug> --bucket= --region= [--endpoint=] [--prefix=] [--path-style]`: saves a site's bucket after
 * checking it works. The keys come from the environment (`SIGHTPRESS_S3_ACCESS_KEY`, `SIGHTPRESS_S3_SECRET_KEY`), so
 * they never appear in the shell history or the process list, and are stored encrypted. The site keeps using its
 * current storage until `site:storage:migrate`.
 */
final readonly class SiteStorageS3Command implements Command
{
    public const string SETTING = 'storage.s3';

    public function __construct(
        private SiteRepository $sites,
        private SecretBox $secrets,
        private SiteFilesystems $filesystems,
        private SiteContainerFactory $containers,
        private LoggerFactory $loggers,
    ) {
    }

    public function name(): string
    {
        return 'site:storage:s3';
    }

    public function description(): string
    {
        return 'Connects a site to an S3-compatible bucket. Its files stay where they are until you run site:storage:migrate.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('bucket', 'Name of the bucket.', CommandParameterType::Option),
            new CommandParameter('region', 'Region of the bucket, for example eu-west-2.', CommandParameterType::Option),
            new CommandParameter('endpoint', 'Address of the provider, if it is not AWS.', CommandParameterType::Option),
            new CommandParameter('prefix', 'Folder inside the bucket. The default is sites/ and the site id.', CommandParameterType::Option),
            new CommandParameter('path-style', 'Put the bucket name in the path of the address (MinIO needs this).', CommandParameterType::Flag),
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
        $current = $site->storage->s3;
        $accessKey = self::environment('SIGHTPRESS_S3_ACCESS_KEY');
        $secretKey = self::environment('SIGHTPRESS_S3_SECRET_KEY');
        if (($accessKey === null || $secretKey === null) && $current === null) {
            $output->error('Set SIGHTPRESS_S3_ACCESS_KEY and SIGHTPRESS_S3_SECRET_KEY in the environment, then run this again.');

            return self::FAILURE;
        }

        try {
            $s3 = new SiteS3Storage(
                trim((string) ($input->option('bucket') ?? $current?->bucket)),
                trim((string) ($input->option('region') ?? $current?->region)),
                $input->option('endpoint') ?? $current?->endpoint,
                trim($input->option('prefix') ?? $current->prefix ?? 'sites/' . $site->id, " \t\n\r\0\x0B/"),
                $input->flag('path-style') || $current?->pathStyle === true,
                $accessKey === null ? (string) $current?->accessKey : $this->secrets->encrypt($accessKey),
                $secretKey === null ? (string) $current?->secretKey : $this->secrets->encrypt($secretKey),
            );
            foreach ($this->sites->all() as $other) {
                if ($other->slug !== $slug && $other->storage->s3?->overlaps($s3) === true) {
                    throw new StorageException(sprintf('The site "%s" already uses this folder of the bucket. Choose another --prefix.', $other->slug));
                }
            }
            $this->filesystems->check($s3);
        } catch (SiteRegistryException | StorageException $exception) {
            $output->error($exception->getMessage() . ' Nothing was changed.');

            return self::FAILURE;
        }

        $changed = $site->withStorage($site->storage->withS3($s3));
        $this->sites->save($changed);
        $this->containers->evict($slug);
        Services::get($this->containers->forSite($changed), EventDispatcherInterface::class)->dispatch(new SettingsChanged([self::SETTING], null));
        $this->loggers->create('sites')->notice(sprintf('Site "%s" storage bucket set.', $slug), ['site' => $slug, 'bucket' => $s3->bucket, 'region' => $s3->region]);
        $output->line(sprintf('The bucket "%s" works and has been saved for "%s".', $s3->bucket, $slug));
        $output->line($changed->storage->isCloud()
            ? 'The site is already using this storage.'
            : sprintf('The site still uses its local files. Move them with site:storage:migrate %s.', $slug));

        return self::SUCCESS;
    }

    private static function environment(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
