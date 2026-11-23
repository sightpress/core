<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Cli\ConsoleCommandInput;
use SightPress\Core\Cli\ModuleCommand;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Media\Command\SiteMediaImportCommand;
use SightPress\Core\Media\ImageProcessor;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(SiteMediaImportCommand::class)]
#[CoversClass(MediaUploader::class)]
#[CoversClass(ImageProcessor::class)]
#[CoversClass(ModuleCommand::class)]
#[CoversClass(ConsoleCommandInput::class)]
#[UsesNamespace('SightPress\Core')]
final class SiteMediaImportCommandTest extends TestCase
{
    private TestProject $project;

    private string $stdout = '';

    private string $stderr = '';

    private string $memoryLimit;

    protected function setUp(): void
    {
        $this->memoryLimit = (string) ini_get('memory_limit');
        $this->project = new TestProject(['media' => ['limits' => ['video' => '1K', 'document' => '1K', 'pixels' => 100]]]);
        self::assertSame(0, $this->console([
            'command' => 'site:create',
            'slug' => 'demo',
            '--domain' => 'demo.example',
            '--db' => 'sqlite:///' . $this->project->path() . '/databases/demo.sqlite',
            '--owner-type' => 'organization',
            '--admin-email' => 'ada@example.org',
            '--admin-first-name' => 'Ada',
            '--admin-last-name' => 'Lovelace',
        ]), $this->stderr);
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memoryLimit);
        $this->project->remove();
    }

    private function kernel(): Kernel
    {
        return Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(array $input): int
    {
        $application = $this->kernel()->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $status = $tester->run($input, ['interactive' => false, 'capture_stderr_separately' => true]);
        $this->stdout = $tester->getDisplay();
        $this->stderr = $tester->getErrorOutput();

        return $status;
    }

    /**
     * @param list<string>         $paths
     * @param array<string, mixed> $options
     */
    private function import(array $paths, array $options = []): int
    {
        return $this->console(['command' => 'site:media:import', 'site' => 'demo', 'paths' => $paths, ...$options]);
    }

    private function site(): ContainerInterface
    {
        $kernel = $this->kernel();
        $site = Services::get($kernel->container(), SiteRepository::class)->find('demo') ?? throw new \LogicException('No site.');

        return Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site);
    }

    private function file(string $name, string $contents): string
    {
        $this->project->directory->write('import/' . $name, $contents);

        return $this->project->path() . '/import/' . $name;
    }

    public function testImportsAFileAboveEveryUploadLimitAndAuditsIt(): void
    {
        $file = $this->file('notes.txt', str_repeat('Opening times. ', 500));

        self::assertSame(0, $this->import([$file]), $this->stderr);
        self::assertStringContainsString('Imported: ' . $file, $this->stdout);
        self::assertStringContainsString('Imported: 1. Already present: 0. Refused: 0.', $this->stdout);

        $entries = Services::get($this->site(), AuditLog::class)->find(new AuditFilter(), 5);
        self::assertSame('media.uploaded', $entries[0]->action);
        self::assertNull($entries[0]->actorId);
    }

    public function testAReimportIsAlreadyPresent(): void
    {
        $file = $this->file('notes.txt', 'Opening times.');
        self::assertSame(0, $this->import([$file]));
        self::assertSame(0, $this->import([$file]));
        self::assertStringContainsString('Already present: ' . $file, $this->stdout);
        self::assertStringContainsString('Imported: 0. Already present: 1. Refused: 0.', $this->stdout);
    }

    public function testRefusesFakeTypesAndScriptedSvgAndKeepsGoing(): void
    {
        $this->file('tree/a-fake.jpg', '<?php echo "not a picture";');
        $this->file('tree/b-script.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $good = $this->file('tree/c-good.txt', 'Fine.');
        $this->file('tree/.hidden.txt', 'Skipped.');

        self::assertSame(1, $this->import([$this->project->path() . '/import/tree'], ['--recursive' => true]));
        self::assertStringContainsString('a-fake.jpg: This type of file can', $this->stderr);
        self::assertStringContainsString('b-script.svg: This type of file can', $this->stderr);
        self::assertStringContainsString('Imported: ' . $good, $this->stdout);
        self::assertStringNotContainsString('.hidden.txt', $this->stdout . $this->stderr);
        self::assertStringContainsString('Imported: 1. Already present: 0. Refused: 2.', $this->stdout);
    }

    public function testExplainsFoldersAndMissingPaths(): void
    {
        $this->file('folder/a.txt', 'A');
        self::assertSame(1, $this->import([$this->project->path() . '/import/folder', $this->project->path() . '/nope']));
        self::assertStringContainsString('Add --recursive', $this->stderr);
        self::assertStringContainsString('There is no such file or folder.', $this->stderr);
    }

    public function testPublishesAndDescribesNewFiles(): void
    {
        $file = $this->file('pic.jpg', MediaFixture::jpeg(8, 8));
        self::assertSame(0, $this->import([$file], ['--publish' => true, '--alt' => 'A small square', '--locale' => 'en-GB']), $this->stderr);

        $media = Services::get($this->site(), MediaRepository::class)->findByChecksum((string) hash_file('sha256', $file));
        self::assertNotNull($media);
        self::assertTrue($media->isPublished());
        self::assertSame('A small square', $media->altText('en-GB'));
    }

    public function testALargePictureSaysTheMemoryItNeedsAndCanBeRaised(): void
    {
        $file = $this->file('big.jpg', MediaFixture::jpeg(40, 40));

        self::assertSame(1, $this->import([$file]));
        self::assertStringContainsString('needs about 1 MB of memory. Run the import again with --memory-limit=1M.', $this->stderr);

        self::assertSame(0, $this->import([$file], ['--memory-limit' => '-1']), $this->stderr);
        self::assertStringContainsString('Imported: 1.', $this->stdout);
    }

    public function testAMemoryLimitMakesRoomForThatManyPixels(): void
    {
        $file = $this->file('big.jpg', MediaFixture::jpeg(40, 40));

        self::assertSame(0, $this->import([$file], ['--memory-limit' => '1G']), $this->stderr);
    }

    public function testAStorageFailureIsARefusalThatNamesTheCommand(): void
    {
        $kernel = $this->kernel();
        $site = Services::get($kernel->container(), SiteRepository::class)->find('demo') ?? throw new \LogicException('No site.');
        $folder = $site->storage->root($kernel->config()->sitesDir, $site->id);
        $file = $this->file('a.txt', 'Opening times: 9 to 5.');
        chmod($folder, 0o555);
        try {
            self::assertSame(1, $this->import([$file]));
            self::assertStringContainsString('storage couldn\'t keep this file', $this->stderr);
        } finally {
            chmod($folder, 0o777);
        }
    }

    public function testRefusesBadInput(): void
    {
        $file = $this->file('a.txt', 'A');
        self::assertSame(1, $this->console(['command' => 'site:media:import', 'site' => 'ghost', 'paths' => [$file]]));
        self::assertStringContainsString('There is no site', $this->stderr);
        self::assertSame(1, $this->import([$file], ['--locale' => 'fr']));
        self::assertStringContainsString('isn\'t written in "fr"', $this->stderr);
        self::assertSame(1, $this->import([$file], ['--memory-limit' => 'lots']));
        self::assertStringContainsString('--memory-limit must be a size', $this->stderr);
    }
}
