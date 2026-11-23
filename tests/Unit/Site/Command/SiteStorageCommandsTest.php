<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site\Command;

use Aws\S3\S3Client;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Database\DatabaseProvisioner;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Infrastructure\Storage\SiteFilesystems;
use SightPress\Core\Infrastructure\Storage\StorageMigrator;
use SightPress\Core\Kernel;
use SightPress\Core\Site\Command\PurgeSiteCommand;
use SightPress\Core\Site\Command\SiteStorageMigrateCommand;
use SightPress\Core\Site\Command\SiteStorageS3Command;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRegistryException;
use SightPress\Core\Tenant\SiteRegistryFormat;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tests\Support\FakeS3;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(SiteStorageS3Command::class)]
#[CoversClass(SiteStorageMigrateCommand::class)]
#[CoversClass(PurgeSiteCommand::class)]
#[CoversClass(Site::class)]
#[CoversClass(SiteRegistryFormat::class)]
#[UsesNamespace('SightPress\Core')]
final class SiteStorageCommandsTest extends TestCase
{
    private TestProject $project;

    private Kernel $kernel;

    private FakeS3 $s3;

    /** @var list<string> */
    private array $out = [];

    /** @var list<string> */
    private array $err = [];

    protected function setUp(): void
    {
        putenv('APP_SECRET=' . SiteFixture::SECRET);
        $_ENV['APP_SECRET'] = SiteFixture::SECRET;
        putenv('SIGHTPRESS_S3_ACCESS_KEY=AKIATESTKEY');
        putenv('SIGHTPRESS_S3_SECRET_KEY=test-secret-key');
        $this->s3 = new FakeS3();
        $this->project = new TestProject();
        $this->kernel = Kernel::boot($this->project->path(), ['APP_SECRET' => SiteFixture::SECRET]);
        $console = $this->kernel->console();
        $console->setAutoExit(false);
        $tester = new ApplicationTester($console);
        foreach (['one', 'two'] as $slug) {
            self::assertSame(0, $tester->run([
                'command' => 'site:create',
                'slug' => $slug,
                '--domain' => $slug . '.example',
                '--db' => 'sqlite:///' . $this->project->path() . '/databases/' . $slug . '.sqlite',
                '--owner-type' => 'organization',
                '--admin-email' => 'ada@example.org',
                '--admin-first-name' => 'Ada',
                '--admin-last-name' => 'Lovelace',
            ], ['interactive' => false, 'capture_stderr_separately' => true]), $tester->getErrorOutput());
        }
    }

    protected function tearDown(): void
    {
        putenv('APP_SECRET');
        unset($_ENV['APP_SECRET']);
        putenv('SIGHTPRESS_S3_ACCESS_KEY');
        putenv('SIGHTPRESS_S3_SECRET_KEY');
        $this->project->remove();
    }

    private function sites(): SiteRepository
    {
        return Services::get($this->kernel->container(), SiteRepository::class);
    }

    private function filesystems(): SiteFilesystems
    {
        return new SiteFilesystems(SiteFixture::secretBox(), $this->kernel->config()->sitesDir, fn (array $options): S3Client => new S3Client([...$options, 'http_handler' => $this->s3]));
    }

    /**
     * @param array<string, string|true> $options
     */
    private function run_(Command $command, string $slug, array $options = []): int
    {
        $this->out = [];
        $this->err = [];
        $input = new class ($slug, $options) implements CommandInput {
            /** @param array<string, string|true> $options */
            public function __construct(private string $slug, private array $options)
            {
            }

            public function argument(string $name): string
            {
                return $this->slug;
            }

            public function option(string $name): ?string
            {
                $value = $this->options[$name] ?? null;

                return is_string($value) ? $value : null;
            }

            public function flag(string $name): bool
            {
                return isset($this->options[$name]);
            }
        };
        $output = new class () implements CommandOutput {
            /** @var list<string> */
            public array $lines = [];

            /** @var list<string> */
            public array $errors = [];

            public function line(string $text = ''): void
            {
                $this->lines[] = $text;
            }

            public function error(string $text): void
            {
                $this->errors[] = $text;
            }
        };
        $status = $command->run($input, $output);
        $this->out = $output->lines;
        $this->err = $output->errors;

        return $status;
    }

    private function connect(): SiteStorageS3Command
    {
        $container = $this->kernel->container();

        return new SiteStorageS3Command($this->sites(), Services::get($container, SecretBox::class), $this->filesystems(), Services::get($container, SiteContainerFactory::class), Services::get($container, LoggerFactory::class));
    }

    private function migrate(): SiteStorageMigrateCommand
    {
        $container = $this->kernel->container();

        return new SiteStorageMigrateCommand($this->sites(), $this->filesystems(), new StorageMigrator(), Services::get($container, SiteContainerFactory::class), Services::get($container, LoggerFactory::class));
    }

    private function purge(): PurgeSiteCommand
    {
        $container = $this->kernel->container();

        return new PurgeSiteCommand($this->sites(), Services::get($container, SecretBox::class), Services::get($container, DatabaseProvisioner::class), Services::get($container, SiteContainerFactory::class), Services::get($container, LoggerFactory::class), $this->filesystems(), $this->kernel->config());
    }

    private function site(string $slug = 'one'): Site
    {
        return $this->sites()->find($slug) ?? throw new \LogicException('No site.');
    }

    private function folder(string $slug = 'one'): string
    {
        return $this->kernel->config()->sitesDir . '/' . $this->site($slug)->id;
    }

    private function addLocalFile(string $path, string $contents, string $slug = 'one'): void
    {
        $this->filesystems()->local($this->site($slug))->write($path, $contents);
    }

    public function testARegistryWithBadBucketSettingsIsRefused(): void
    {
        $this->expectException(SiteRegistryException::class);
        $this->expectExceptionMessage('"storage.s3" must be an array.');

        SiteRegistryFormat::parse([
            'version' => SiteRegistryFormat::VERSION,
            'sites' => ['one' => SiteFixture::entry('one', ['storage' => ['adapter' => 'local', 's3' => 'not-an-array']])],
        ], 'config/sites.php');
    }

    public function testDescribesItself(): void
    {
        self::assertSame('site:storage:s3', $this->connect()->name());
        self::assertSame('site:storage:migrate', $this->migrate()->name());
        self::assertStringContainsString('bucket', $this->connect()->description());
        self::assertStringContainsString('--delete-source', $this->migrate()->description());
        self::assertCount(6, $this->connect()->parameters());
        self::assertCount(2, $this->migrate()->parameters());
    }

    public function testConnectsWithEncryptedKeysAndStaysLocal(): void
    {
        self::assertSame(0, $this->run_($this->connect(), 'one', ['bucket' => 'media-bucket', 'region' => 'eu-west-2', 'endpoint' => 'http://minio:9000', 'path-style' => true]), implode("\n", $this->err));
        $site = $this->site();
        self::assertFalse($site->storage->isCloud());
        self::assertNotNull($site->storage->s3);
        self::assertSame('sites/' . $site->id, $site->storage->s3->prefix);
        self::assertSame('AKIATESTKEY', SiteFixture::secretBox()->decrypt($site->storage->s3->accessKey));
        $registry = (string) file_get_contents($this->project->path() . '/config/sites.php');
        self::assertStringNotContainsString('AKIATESTKEY', $registry);
        self::assertStringNotContainsString('test-secret-key', $registry);
        self::assertStringNotContainsString('test-secret-key', implode("\n", $this->out));

        putenv('SIGHTPRESS_S3_ACCESS_KEY');
        putenv('SIGHTPRESS_S3_SECRET_KEY');
        self::assertSame(0, $this->run_($this->connect(), 'one', ['region' => 'eu-west-1']), implode("\n", $this->err));
        $updated = $this->site()->storage->s3;
        self::assertNotNull($updated);
        self::assertSame('eu-west-1', $updated->region);
        self::assertSame('AKIATESTKEY', SiteFixture::secretBox()->decrypt($updated->accessKey));
    }

    public function testConnectRefusals(): void
    {
        self::assertSame(1, $this->run_($this->connect(), 'ghost', ['bucket' => 'media-bucket', 'region' => 'eu-west-2']));
        self::assertStringContainsString('There is no site', $this->err[0]);

        self::assertSame(1, $this->run_($this->connect(), 'one', ['bucket' => 'X', 'region' => 'eu-west-2']));
        self::assertStringContainsString('Nothing was changed', $this->err[0]);

        $this->s3->errorCode = 'InvalidAccessKeyId';
        self::assertSame(1, $this->run_($this->connect(), 'one', ['bucket' => 'media-bucket', 'region' => 'eu-west-2']));
        self::assertStringContainsString('refused the access key', $this->err[0]);
        self::assertStringNotContainsString('test-secret-key', $this->err[0]);
        self::assertNull($this->site()->storage->s3);
        $this->s3->errorCode = null;

        putenv('SIGHTPRESS_S3_SECRET_KEY');
        self::assertSame(1, $this->run_($this->connect(), 'one', ['bucket' => 'media-bucket', 'region' => 'eu-west-2']));
        self::assertStringContainsString('SIGHTPRESS_S3_ACCESS_KEY', $this->err[0]);
    }

    public function testTwoSitesCannotShareAFolderOfABucket(): void
    {
        self::assertSame(0, $this->run_($this->connect(), 'one', ['bucket' => 'media-bucket', 'region' => 'eu-west-2', 'prefix' => 'shared']));
        self::assertSame(1, $this->run_($this->connect(), 'two', ['bucket' => 'media-bucket', 'region' => 'eu-west-2', 'prefix' => 'shared/two']));
        self::assertStringContainsString('The site "one" already uses this folder', $this->err[0]);
    }

    public function testMigratesVerifiesSwitchesAndKeepsLocalCopies(): void
    {
        // A new site already has its generated branding files.
        $existing = count(array_filter($this->filesystems()->local($this->site())->listContents('', true)->toArray(), static fn ($item): bool => $item->isFile()));
        for ($i = 0; $i < 100; ++$i) {
            $this->addLocalFile(sprintf('media/%02d/file-%d.bin', $i % 9, $i), str_repeat('x', $i + 1));
        }
        $total = $existing + 100;
        self::assertSame(0, $this->run_($this->connect(), 'one', ['bucket' => 'media-bucket', 'region' => 'eu-west-2']));
        self::assertSame(0, $this->run_($this->migrate(), 'one'), implode("\n", $this->err));
        self::assertStringContainsString(sprintf('Copied and checked: %d. Already in the bucket: 0. Failed: 0.', $total), $this->out[0]);
        self::assertTrue($this->site()->storage->isCloud());
        self::assertCount($total, $this->s3->objects);
        self::assertFileExists($this->folder() . '/media/01/file-1.bin');

        self::assertSame(0, $this->run_($this->migrate(), 'one', ['delete-source' => true]));
        self::assertStringContainsString('Already in the bucket: ' . $total, $this->out[0]);
        self::assertStringContainsString(sprintf('Deleted %d local copies.', $total), implode("\n", $this->out));
        self::assertFileDoesNotExist($this->folder() . '/media/01/file-1.bin');
    }

    public function testALocalCopyThatCannotBeDeletedIsReported(): void
    {
        $this->addLocalFile('media/locked/a.bin', 'x');
        self::assertSame(0, $this->run_($this->connect(), 'one', ['bucket' => 'media-bucket', 'region' => 'eu-west-2']));
        $locked = $this->folder() . '/media/locked';
        chmod($locked, 0o555);
        try {
            self::assertSame(1, $this->run_($this->migrate(), 'one', ['delete-source' => true]));
            self::assertStringContainsString('Some local copies could not be deleted', $this->err[0]);
            self::assertTrue($this->site()->storage->isCloud());
        } finally {
            chmod($locked, 0o777);
        }
    }

    public function testConnectingAgainOnABucketSiteSaysItIsInUse(): void
    {
        self::assertSame(0, $this->run_($this->connect(), 'one', ['bucket' => 'media-bucket', 'region' => 'eu-west-2']));
        self::assertSame(0, $this->run_($this->migrate(), 'one'));
        self::assertSame(0, $this->run_($this->connect(), 'one', ['region' => 'eu-west-1']));
        self::assertContains('The site is already using this storage.', $this->out);
    }

    public function testAFailedCopyDoesNotSwitchTheSiteOrDeleteAnything(): void
    {
        $this->addLocalFile('media/a.bin', 'real contents');
        self::assertSame(0, $this->run_($this->connect(), 'one', ['bucket' => 'media-bucket', 'region' => 'eu-west-2']));
        // The connection check writes through the same fake, so only the copy is corrupted.
        $filesystems = new SiteFilesystems(SiteFixture::secretBox(), $this->kernel->config()->sitesDir, function (array $options): S3Client {
            return new S3Client([...$options, 'http_handler' => function (RequestInterface $request, array $handlerOptions = []): PromiseInterface {
                /** @var array<string, mixed> $handlerOptions */
                $this->s3->corruptWrites = str_ends_with($request->getUri()->getPath(), 'a.bin') ? 'damaged' : null;

                return ($this->s3)($request, $handlerOptions);
            }]);
        });
        $container = $this->kernel->container();
        $migrate = new SiteStorageMigrateCommand($this->sites(), $filesystems, new StorageMigrator(), Services::get($container, SiteContainerFactory::class), Services::get($container, LoggerFactory::class));
        self::assertSame(1, $this->run_($migrate, 'one', ['delete-source' => true]));
        self::assertContains('Not copied: media/a.bin', $this->err);
        self::assertFalse($this->site()->storage->isCloud());
        self::assertFileExists($this->folder() . '/media/a.bin');
    }

    public function testMigrateRefusals(): void
    {
        self::assertSame(1, $this->run_($this->migrate(), 'ghost'));
        self::assertSame(1, $this->run_($this->migrate(), 'one'));
        self::assertStringContainsString('has no bucket yet', $this->err[0]);
        self::assertSame(0, $this->run_($this->connect(), 'one', ['bucket' => 'media-bucket', 'region' => 'eu-west-2']));
        $this->s3->errorCode = 'AccessDenied';
        self::assertSame(1, $this->run_($this->migrate(), 'one'));
        self::assertStringContainsString('The site was not switched', $this->err[0]);
    }

    public function testPurgeRemovesBothStoragesButOnlyThisPrefix(): void
    {
        self::assertSame(0, $this->run_($this->connect(), 'one', ['bucket' => 'media-bucket', 'region' => 'eu-west-2', 'prefix' => 'sites/a']));
        $this->addLocalFile('media/a.bin', 'x');
        $folder = $this->folder();
        $this->s3->objects['sites/a/media/a.bin'] = 'x';
        $this->s3->objects['sites/ab/media/keep.bin'] = 'keep';
        $this->sites()->save($this->site()->withStatus(SiteStatus::Deleted));

        self::assertSame(0, $this->run_($this->purge(), 'one'), implode("\n", $this->err));
        self::assertSame(['sites/ab/media/keep.bin'], array_keys($this->s3->objects));
        self::assertDirectoryDoesNotExist($folder);
        self::assertNull($this->sites()->find('one'));
    }

    public function testPurgeKeepsAFolderThatHoldsTheDatabase(): void
    {
        $this->addLocalFile('media/a.bin', 'x');
        $folder = $this->folder();
        $site = $this->site();
        $this->sites()->save(new Site($site->id, $site->slug, $site->name, SiteStatus::Deleted, $site->ownerType, $site->domains, SiteFixture::secretBox()->encrypt('sqlite:///' . $folder . '/site.sqlite'), $site->storage, $site->defaultLocale, $site->locales, $site->defaultTimezone, $site->createdAt));
        self::assertSame(0, $this->run_($this->purge(), 'one'), implode("\n", $this->err));
        self::assertFileExists($folder . '/media/a.bin');
        self::assertStringContainsString('was kept', implode("\n", $this->out));
    }

    public function testPurgeStopsWhenTheBucketFailsOrIsShared(): void
    {
        self::assertSame(0, $this->run_($this->connect(), 'one', ['bucket' => 'media-bucket', 'region' => 'eu-west-2', 'prefix' => 'sites/a']));
        $this->sites()->save($this->site()->withStatus(SiteStatus::Deleted));
        $this->s3->errorCode = 'AccessDenied';
        self::assertSame(1, $this->run_($this->purge(), 'one'));
        self::assertStringContainsString('The site was not purged.', $this->err[0]);
        self::assertNotNull($this->sites()->find('one'));
        $this->s3->errorCode = null;

        $two = $this->site('two');
        $this->sites()->save($two->withStorage($two->storage->withS3((clone $this->site())->storage->s3 ?? throw new \LogicException())));
        self::assertSame(1, $this->run_($this->purge(), 'one'));
        self::assertStringContainsString('The site "two" uses the same folder', $this->err[0]);
        self::assertNotNull($this->sites()->find('one'));
    }
}
