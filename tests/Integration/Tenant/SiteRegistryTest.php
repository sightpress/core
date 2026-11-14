<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Tenant;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The site registry end to end: the kernel's repository writes `config/sites.php`, a new process reads it back,
 * and the `site:registry:*` commands check it.
 */
#[CoversNothing]
final class SiteRegistryTest extends TestCase
{
    private TestProject $project;

    protected function setUp(): void
    {
        $this->project = new TestProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testSitesWrittenByOneProcessAreReadByTheNext(): void
    {
        $sites = $this->kernel()->container()->get(SiteRepository::class);
        self::assertInstanceOf(SiteRepository::class, $sites);

        $sites->save(SiteFixture::site('demo', ['demo.example'], dsn: 'mysql://app:hunter2@db/demo'));
        $sites->save(SiteFixture::site('blog', ['blog.example'], SiteStatus::Disabled));

        $registry = (string) file_get_contents($this->project->path() . '/config/sites.php');
        self::assertStringNotContainsString('hunter2', $registry);
        self::assertFileExists($this->project->path() . '/config/sites.php.bak');

        $fresh = Services::get($this->kernel()->container(), SiteRepository::class);
        self::assertSame(['blog', 'demo'], array_keys($fresh->all()));
        self::assertSame(SiteStatus::Disabled, $fresh->findByDomain('blog.example')?->status);
        self::assertSame(
            'mysql://app:hunter2@db/demo',
            SiteFixture::secretBox()->decrypt((string) $fresh->find('demo')?->databaseDsn),
        );

        $check = $this->console(['command' => 'site:registry:check']);
        self::assertSame(0, $check->getStatusCode());
        self::assertSame("The site registry is valid: 2 sites.\n", $check->getDisplay());

        $upgrade = $this->console(['command' => 'site:registry:upgrade']);
        self::assertSame(0, $upgrade->getStatusCode());
        self::assertStringContainsString('already uses format version 1', $upgrade->getDisplay());
    }

    public function testTheCheckNamesWhatIsWrongWithAHandEditedFile(): void
    {
        file_put_contents(
            $this->project->path() . '/config/sites.php',
            '<?php return ' . var_export(['version' => 1, 'sites' => [
                'demo' => SiteFixture::entry('demo', ['status' => 'paused']),
            ]], true) . ';',
        );

        $check = $this->console(['command' => 'site:registry:check'], ['capture_stderr_separately' => true]);

        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('site "demo": "status" is not valid', $check->getErrorOutput());
    }

    private function kernel(): Kernel
    {
        return Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, bool>  $options
     */
    private function console(array $input, array $options = []): ApplicationTester
    {
        $application = $this->kernel()->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run($input, $options);

        return $tester;
    }
}
