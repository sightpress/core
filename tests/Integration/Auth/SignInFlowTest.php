<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Auth;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Auth\PasswordResetTokens;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Kernel;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Core\Rbac\RbacSeeder;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\HelloHandler;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;

/**
 * Signing in and out, "keep me signed in" and choosing a new password, through the whole kernel: a site on a SQLite
 * file, real sessions, CSRF checks and cookies.
 */
#[CoversNothing]
final class SignInFlowTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    private TestProject $project;

    private Kernel $kernel;

    /** @var array<string, string> cookies the browser keeps */
    private array $cookies = [];

    protected function setUp(): void
    {
        $this->project = new TestProject();
        $dsn = 'sqlite:///' . $this->project->path() . '/demo.sqlite';
        $this->project->directory->write('config/sites.php', '<?php return ' . var_export([
            'version' => 1,
            'sites' => ['demo' => SiteFixture::entry('demo', [
                'domains' => ['example.org'],
                'db_dsn' => SiteFixture::secretBox()->encrypt($dsn),
            ])],
        ], true) . ';');
        $admin = new FakeModule('acme.admin', register: static function (ModuleRegistrar $r): void {
            $r->routes()->add(new Route('acme.admin', '/admin/acme', HelloHandler::class, middleware: [AdminAccessMiddleware::class]));
        });
        $this->kernel = Kernel::fromConfig(
            new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]),
            [...CoreModules::candidates(), new ModuleCandidate($admin, 'acme/admin')],
        );

        $site = $this->site();
        $dependencies = DependencyFactory::fromConnection(
            new ExistingConfiguration(new MigrationConfigurationFactory()->create($this->kernel->modules())),
            new ExistingConnection(Services::get($site, Connection::class)),
        );
        $dependencies->getMetadataStorage()->ensureInitialized();
        $dependencies->getMigrator()->migrate(
            $dependencies->getMigrationPlanCalculator()->getPlanUntilVersion(
                $dependencies->getVersionAliasResolver()->resolveVersionAlias('latest'),
            ),
            new MigratorConfiguration(),
        );
        Services::get($site, RbacSeeder::class)->seed();
        $this->user('ada@example.org', 'editor');
        $this->user('max@example.org', 'member');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testStaffSignInAndAreBroughtBackToTheAdminPage(): void
    {
        $door = $this->request('GET', '/admin/acme');
        self::assertSame('/login?return=%2Fadmin%2Facme', $door->getHeaderLine('Location'));

        $form = $this->request('GET', $door->getHeaderLine('Location'));
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('action="/login?return=%2Fadmin%2Facme"', (string) $form->getBody());

        $signedIn = $this->submit($form, '/login?return=%2Fadmin%2Facme', ['email' => 'ada@example.org', 'password' => self::PASSWORD]);
        self::assertSame(303, $signedIn->getStatusCode(), (string) $signedIn->getBody());
        self::assertSame('/admin/acme', $signedIn->getHeaderLine('Location'));

        self::assertSame('hello', (string) $this->request('GET', '/admin/acme')->getBody());
    }

    public function testMembersSignInButAreNeverSentToTheAdminPanel(): void
    {
        $form = $this->request('GET', '/login?return=%2Fadmin%2Facme');

        $signedIn = $this->submit($form, '/login?return=%2Fadmin%2Facme', ['email' => 'max@example.org', 'password' => self::PASSWORD]);

        self::assertSame('/', $signedIn->getHeaderLine('Location'));
        self::assertSame(403, $this->request('GET', '/admin/acme')->getStatusCode());
    }

    public function testAWrongPasswordIsRefusedAndTheFormNeedsItsCsrfToken(): void
    {
        $form = $this->request('GET', '/login');

        $wrong = $this->submit($form, '/login', ['email' => 'ada@example.org', 'password' => 'not the password']);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString('Enter a correct email address and password.', (string) $wrong->getBody());

        $forged = $this->request('POST', '/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD]);
        self::assertSame(403, $forged->getStatusCode());
    }

    public function testSigningOutEndsTheSessionAndForgetsTheDevice(): void
    {
        $form = $this->request('GET', '/login');
        $this->submit($form, '/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD, 'remember' => '1']);
        self::assertArrayHasKey('__Host-sightpress_remember', $this->cookies);

        $confirm = $this->request('GET', '/logout');
        self::assertStringContainsString('<h1>Sign out</h1>', (string) $confirm->getBody());
        $out = $this->submit($confirm, '/logout', []);

        self::assertSame('/login', $out->getHeaderLine('Location'));
        self::assertArrayNotHasKey('__Host-sightpress_remember', $this->cookies);
        self::assertStringContainsString('You have signed out.', (string) $this->request('GET', '/login')->getBody());
        self::assertSame(303, $this->request('GET', '/admin/acme')->getStatusCode());
    }

    public function testKeepMeSignedInWorksAfterTheBrowserClosed(): void
    {
        $form = $this->request('GET', '/login');
        $this->submit($form, '/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD, 'remember' => '1']);
        $remember = $this->cookies['__Host-sightpress_remember'];

        $this->cookies = ['__Host-sightpress_remember' => $remember];
        self::assertSame('hello', (string) $this->request('GET', '/admin/acme')->getBody());
        self::assertNotSame($remember, $this->cookies['__Host-sightpress_remember'], 'the token is replaced each time');

        $this->cookies = ['__Host-sightpress_remember' => $remember];
        self::assertSame(303, $this->request('GET', '/admin/acme')->getStatusCode(), 'a used token signs nobody in');
    }

    public function testChoosingANewPasswordSignsOutOtherSessionsAndDevices(): void
    {
        $form = $this->request('GET', '/login');
        $this->submit($form, '/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD, 'remember' => '1']);
        $otherDevice = $this->cookies;

        $this->cookies = [];
        $forgot = $this->request('GET', '/password/forgot');
        self::assertStringContainsString('<h1>Check your email</h1>', (string) $this->submit($forgot, '/password/forgot', ['email' => 'ada@example.org'])->getBody());

        $user = Services::get($this->site(), UserRepository::class)->findByEmail('ada@example.org');
        self::assertNotNull($user);
        $link = '/password/reset/' . Services::get($this->site(), PasswordResetTokens::class)->create($user);
        $reset = $this->request('GET', $link);
        self::assertStringContainsString('<h1>Choose a new password</h1>', (string) $reset->getBody());
        $changed = $this->submit($reset, $link, ['password' => 'a completely new password']);
        self::assertSame('/login', $changed->getHeaderLine('Location'));
        $signIn = $this->request('GET', '/login');
        self::assertStringContainsString('Your password has been changed.', (string) $signIn->getBody());
        self::assertSame(410, $this->request('GET', $link)->getStatusCode(), 'the link works once');
        self::assertSame(422, $this->submit($signIn, '/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD])->getStatusCode());
        self::assertSame(303, $this->submit($this->request('GET', '/login'), '/login', ['email' => 'ada@example.org', 'password' => 'a completely new password'])->getStatusCode());

        $this->cookies = $otherDevice;
        self::assertSame(303, $this->request('GET', '/admin/acme')->getStatusCode(), 'the other device is signed out');
    }

    private function site(): ContainerInterface
    {
        $site = Services::get($this->kernel->container(), SiteRepository::class)->find('demo');
        self::assertNotNull($site);

        return Services::get($this->kernel->container(), SiteContainerFactory::class)->forSite($site);
    }

    private function user(string $email, string $role): void
    {
        $site = $this->site();
        $user = new User($email, 'Ada', 'Lovelace', 'UTC', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher()->hash(self::PASSWORD), new DateTimeImmutable());
        Services::get($site, UserRepository::class)->save($user);
        $roles = Services::get($site, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));
    }

    /**
     * Sends the form on a page back with its CSRF token.
     *
     * @param array<string, string> $fields
     */
    private function submit(ResponseInterface $page, string $path, array $fields): ResponseInterface
    {
        preg_match_all('/<input type="hidden" name="(csrf_[a-z]+)" value="([^"]+)">/', (string) $page->getBody(), $hidden, PREG_SET_ORDER);
        self::assertCount(2, $hidden, 'the form has its CSRF token');
        foreach ($hidden as [, $name, $value]) {
            $fields[$name] = html_entity_decode($value);
        }

        return $this->request('POST', $path, $fields);
    }

    /**
     * @param array<string, string> $body
     */
    private function request(string $method, string $path, array $body = []): ResponseInterface
    {
        $request = new ServerRequest($method, 'https://example.org' . $path, [], null, '1.1', ['REMOTE_ADDR' => '192.0.2.20'])
            ->withCookieParams($this->cookies);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }

        $response = $this->kernel->handle($request);
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = explode('=', $pair, 2);
            if ($value === '' || str_contains($cookie, 'Max-Age=0')) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        return $response;
    }
}
