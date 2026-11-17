<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Site;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestDatabases;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * C-2.3 acceptance: two sites made with `site:create` have their own databases, users and roles, and each answers
 * only for its own accounts.
 */
#[CoversNothing]
final class SiteCreationTest extends TestCase
{
    private TestProject $project;

    /** @var array<string, string> DSN per slug */
    private array $databases = [];

    protected function setUp(): void
    {
        $this->project = new TestProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testTwoNewSitesAreIsolatedFromEachOther(): void
    {
        $alphaCode = $this->create('alpha', 'ada@example.org', 'Ada');
        $betaCode = $this->create('beta', 'grace@example.org', 'Grace');

        $kernel = $this->kernel();
        $sites = Services::get($kernel->container(), SiteRepository::class);
        self::assertSame(['alpha', 'beta'], array_keys($sites->all()));

        $alpha = $this->services($kernel, 'alpha');
        $beta = $this->services($kernel, 'beta');
        self::assertNotSame(Services::get($alpha, Connection::class), Services::get($beta, Connection::class));
        self::assertSame(1, Services::get($alpha, UserRepository::class)->count());
        self::assertSame(1, Services::get($beta, UserRepository::class)->count());
        self::assertNotNull(Services::get($alpha, UserRepository::class)->findByEmail('ada@example.org'));
        self::assertNull(Services::get($alpha, UserRepository::class)->findByEmail('grace@example.org'));
        self::assertNull(Services::get($beta, UserRepository::class)->findByEmail('ada@example.org'));
        foreach ([$alpha, $beta] as $site) {
            self::assertSame(['admin', 'contributor', 'editor', 'member'], $this->roleNames($site));
            self::assertSame('member', Services::get($site, SiteSettings::class)->get('rbac.registration_role'));
        }

        // A change on one site leaves the other alone.
        $alphaRoles = Services::get($alpha, RoleRepository::class);
        $editor = $alphaRoles->findRole('editor');
        self::assertNotNull($editor);
        $alphaRoles->remove($editor);
        self::assertSame(['admin', 'contributor', 'member'], $this->roleNames($alpha));
        self::assertSame(['admin', 'contributor', 'editor', 'member'], $this->roleNames($beta));

        // Each first admin types their setup code, chooses a password and signs in to their own site only.
        $this->choosePassword($kernel, 'https://alpha.example', 'ada@example.org', $alphaCode, 'alpha admin long password');
        $this->choosePassword($kernel, 'https://beta.example', 'grace@example.org', $betaCode, 'beta admin long password');

        $onAlpha = new Browser($kernel, 'https://alpha.example');
        self::assertSame('/admin', $onAlpha->submit('/login', ['email' => 'ada@example.org', 'password' => 'alpha admin long password'])->getHeaderLine('Location'));
        $onBeta = new Browser($kernel, 'https://beta.example');
        self::assertSame(422, $onBeta->submit('/login', ['email' => 'ada@example.org', 'password' => 'alpha admin long password'])->getStatusCode());
        self::assertSame('/admin', $onBeta->submit('/login', ['email' => 'grace@example.org', 'password' => 'beta admin long password'])->getHeaderLine('Location'));

        // A session of one site doesn't sign anyone in on the other, even with the cookie copied over.
        $copied = new Browser($kernel, 'https://beta.example');
        $copied->cookies = $onAlpha->cookies;
        self::assertSame(303, $copied->request('GET', '/logout')->getStatusCode(), 'not signed in on beta');
    }

    /**
     * Runs `site:create` and returns the first admin's setup code, as printed for the operator.
     */
    private function create(string $slug, string $email, string $firstName): string
    {
        $application = $this->kernel()->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $status = $tester->run([
            'command' => 'site:create',
            'slug' => $slug,
            '--domain' => $slug . '.example',
            '--db' => $this->database($slug),
            '--create-db' => true,
            '--owner-type' => 'organization',
            '--locale' => 'en-GB',
            '--admin-email' => $email,
            '--admin-first-name' => $firstName,
            '--admin-last-name' => 'Example',
        ], ['interactive' => false, 'capture_stderr_separately' => true]);

        self::assertSame(0, $status, $tester->getErrorOutput());
        $display = $tester->getDisplay();
        self::assertSame(1, preg_match('#Give the setup code (\d{3} \d{3}) to \S+\. They go to https://' . $slug . '\.example/login/setup,#', $display, $code), $display);
        self::assertStringNotContainsString('/password/reset', $display, 'no link-based route is left');

        return $code[1];
    }

    private function choosePassword(Kernel $kernel, string $origin, string $email, string $code, string $password): void
    {
        $browser = new Browser($kernel, $origin);
        $setup = $browser->submit('/login/setup', ['email' => $email, 'code' => $code]);
        self::assertSame('/login/password', $setup->getHeaderLine('Location'), (string) $setup->getBody());
        $response = $browser->submit('/login/password', ['password' => $password]);

        self::assertSame('/login', $response->getHeaderLine('Location'), (string) $response->getBody());
    }

    /**
     * @return list<string>
     */
    private function roleNames(ContainerInterface $site): array
    {
        $names = array_map(static fn ($role) => $role->name(), Services::get($site, RoleRepository::class)->roles());
        sort($names);

        return $names;
    }

    private function kernel(): Kernel
    {
        return Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
    }

    private function services(Kernel $kernel, string $slug): ContainerInterface
    {
        $site = Services::get($kernel->container(), SiteRepository::class)->find($slug);
        self::assertNotNull($site);

        return Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site);
    }

    private function database(string $slug): string
    {
        return $this->databases[$slug] ??= TestDatabases::dsn($slug, $this->project->path() . '/sites', create: false);
    }
}
