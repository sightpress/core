<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Media\Command\SiteStorageCdnCommand;
use SightPress\Core\Media\MediaUrlSigner;
use SightPress\Core\Template\AssetUrls;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRegistryFormat;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(SiteStorageCdnCommand::class)]
#[CoversClass(SiteRegistryFormat::class)]
#[CoversClass(SiteContainerFactory::class)]
#[UsesNamespace('SightPress\Core')]
final class SiteStorageCdnCommandTest extends TestCase
{
    private TestProject $project;

    private string $stdout = '';

    private string $stderr = '';

    protected function setUp(): void
    {
        $this->project = new TestProject();
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
        $this->project->remove();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(array $input): int
    {
        $application = Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET])->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $status = $tester->run($input, ['interactive' => false, 'capture_stderr_separately' => true]);
        $this->stdout = $tester->getDisplay();
        $this->stderr = $tester->getErrorOutput();

        return $status;
    }

    private function cdn(): ?string
    {
        $kernel = Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);

        return Services::get($kernel->container(), SiteRepository::class)->find('demo')?->storage->cdnUrl;
    }

    public function testSetsShowsAndRemovesTheCdn(): void
    {
        self::assertSame(0, $this->console(['command' => 'site:storage:cdn', 'slug' => 'demo']));
        self::assertStringContainsString('delivers its files itself', $this->stdout);

        self::assertSame(0, $this->console(['command' => 'site:storage:cdn', 'slug' => 'demo', '--url' => 'https://cdn.example/']), $this->stderr);
        self::assertStringContainsString('theme:publish-assets', $this->stdout);
        self::assertSame('https://cdn.example', $this->cdn());

        self::assertSame(0, $this->console(['command' => 'site:storage:cdn', 'slug' => 'demo']));
        self::assertStringContainsString('through https://cdn.example', $this->stdout);
        self::assertSame(0, $this->console(['command' => 'site:storage:cdn', 'slug' => 'demo', '--url' => 'https://cdn.example']));
        self::assertStringContainsString('Nothing was changed.', $this->stdout);

        self::assertSame(0, $this->console(['command' => 'site:storage:cdn', 'slug' => 'demo', '--remove' => true]));
        self::assertStringContainsString('now delivers its files itself', $this->stdout);
        self::assertNull($this->cdn());
    }

    public function testTheSiteContainerUsesTheCdnForStylesAndCanSign(): void
    {
        self::assertSame(0, $this->console(['command' => 'site:storage:cdn', 'slug' => 'demo', '--url' => 'https://cdn.example']), $this->stderr);
        $kernel = Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
        $site = Services::get($kernel->container(), SiteRepository::class)->find('demo') ?? throw new \LogicException('No site.');
        $container = Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site);

        self::assertStringStartsWith('https://cdn.example/assets/tokens.css', Services::get($container, AssetUrls::class)->url('tokens.css'));
        self::assertTrue(Services::get($container, MediaUrlSigner::class)->isAvailable());
    }

    public function testRefusals(): void
    {
        self::assertSame(1, $this->console(['command' => 'site:storage:cdn', 'slug' => 'ghost']));
        self::assertStringContainsString('There is no site', $this->stderr);
        self::assertSame(1, $this->console(['command' => 'site:storage:cdn', 'slug' => 'demo', '--url' => 'http://cdn.example']));
        self::assertStringContainsString('Nothing was changed.', $this->stderr);
        self::assertSame(1, $this->console(['command' => 'site:storage:cdn', 'slug' => 'demo', '--url' => 'https://cdn.example', '--remove' => true]));
        self::assertStringContainsString('either --url or --remove', $this->stderr);
    }
}
