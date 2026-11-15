<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Site;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Kernel;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteMigrator;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestDatabases;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * C-2.4 acceptance: `site:db:change` only moves a disabled site, refuses a database that is behind unless asked to
 * migrate it, stores the new address encrypted, logs the change without credentials, and the same process uses the
 * new database straight away (the cached site container is dropped).
 */
#[CoversNothing]
final class SiteDatabaseChangeTest extends TestCase
{
    private TestProject $project;

    private Kernel $kernel;

    /** @var array<string, string> DSN per name */
    private array $databases = [];

    protected function setUp(): void
    {
        $this->project = new TestProject();
        $this->kernel = Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testADisabledSiteMovesToANewDatabase(): void
    {
        self::assertSame(0, $this->console([
            'command' => 'site:create',
            'slug' => 'demo',
            '--domain' => 'demo.example',
            '--db' => $this->dsn('old', create: false),
            '--create-db' => true,
            '--owner-type' => 'organization',
            '--locale' => 'en-GB',
            '--admin-email' => 'ada@example.org',
            '--admin-first-name' => 'Ada',
            '--admin-last-name' => 'Lovelace',
        ]));
        $onOld = $this->services();
        self::assertSame(1, Services::get($onOld, UserRepository::class)->count());

        foreach ([null, 'site:archive', 'site:delete', 'site:enable'] as $command) {
            if ($command !== null) {
                $this->console(['command' => $command, 'slug' => 'demo']);
            }
            self::assertSame(1, $this->console(['command' => 'site:db:change', 'slug' => 'demo', '--db' => $this->dsn('new'), '--migrate' => true]));
        }

        $this->console(['command' => 'site:disable', 'slug' => 'demo']);
        self::assertSame(1, $this->console(['command' => 'site:db:change', 'slug' => 'demo', '--db' => $this->dsn('new')]), 'behind without --migrate');
        self::assertSame($this->dsn('old'), $this->plainDsn());

        self::assertSame(0, $this->console(['command' => 'site:db:change', 'slug' => 'demo', '--db' => $this->dsn('new'), '--migrate' => true]));
        $site = $this->site();
        self::assertTrue(SecretBox::looksEncrypted($site->databaseDsn));
        self::assertSame($this->dsn('new'), $this->plainDsn());
        self::assertSame(0, Services::get($this->kernel->container(), SiteMigrator::class)->pending($site));

        // The same process now works on the new, empty database: no data was copied.
        $onNew = $this->services();
        self::assertNotSame($onOld, $onNew);
        self::assertSame(0, Services::get($onNew, UserRepository::class)->count());

        $this->console(['command' => 'site:enable', 'slug' => 'demo']);
        $login = new Browser($this->kernel, 'https://demo.example')->submit('/login', ['email' => 'ada@example.org', 'password' => 'any long password here']);
        self::assertSame(422, $login->getStatusCode(), 'the admin lives in the old database only');

        $log = $this->project->log();
        self::assertMatchesRegularExpression('/Site "demo" database changed from (SQLite file|\S+ database "\w+" on) /', $log);
        self::assertStringNotContainsString('://', $log, 'the log never has the DSN itself');
        $password = parse_url($this->dsn('new'), PHP_URL_PASS);
        if (is_string($password) && $password !== '') {
            self::assertStringNotContainsString($password, $log);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(array $input): int
    {
        $application = $this->kernel->console();
        $application->setAutoExit(false);

        return new ApplicationTester($application)->run($input, ['interactive' => false, 'capture_stderr_separately' => true]);
    }

    private function site(): Site
    {
        return Services::get($this->kernel->container(), SiteRepository::class)->find('demo') ?? throw new \LogicException('No site demo');
    }

    private function plainDsn(): string
    {
        return SiteFixture::secretBox()->decrypt($this->site()->databaseDsn);
    }

    private function services(): \Psr\Container\ContainerInterface
    {
        return Services::get($this->kernel->container(), SiteContainerFactory::class)->forSite($this->site());
    }

    private function dsn(string $name, bool $create = true): string
    {
        return $this->databases[$name] ??= TestDatabases::dsn($name, $this->project->path() . '/sites', $create);
    }
}
