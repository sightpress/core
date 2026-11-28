<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Http\Session;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Http\Session\DatabaseSessionStore;
use SightPress\Core\Http\Session\SessionMiddleware;
use SightPress\Core\Http\Session\SessionStore;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Core\Rbac\RbacSeeder;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\RedisServer;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteMigrator;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\HelloHandler;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestDatabases;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;

/**
 * Shared sessions (H-9) through the whole kernel: two kernels on one project stand for two web servers behind one
 * proxy without sticky sessions, and each request goes to the other one. Sign-in, CSRF tokens and flash messages
 * work across them with every store; sign-in gives a new id, sign-out deletes the session, and a raw id is never
 * stored. Redis runs when SIGHTPRESS_TEST_REDIS_DSN names a server.
 */
#[CoversNothing]
final class SharedSessionsFlowTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private TestProject $project;

    /** @var list<Kernel> */
    private array $servers = [];

    private int $next = 0;

    /** @var array<string, string> */
    private array $cookies = [];

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            $this->project->remove();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stores(): iterable
    {
        yield 'database' => ['database'];
        yield 'redis' => ['redis'];
        yield 'files' => ['files'];
    }

    #[DataProvider('stores')]
    public function testTwoServersShareSignInCsrfTokensAndFlashMessages(string $store): void
    {
        $this->servers($store);

        $form = $this->request('GET', '/login');
        $first = $this->sessionId();
        $signedIn = $this->submit($form, '/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD]);
        self::assertSame(303, $signedIn->getStatusCode(), 'the other server accepted the CSRF token: ' . $signedIn->getBody());
        $second = $this->sessionId();
        self::assertNotSame($first, $second, 'signing in gives the session a new id');
        self::assertNull($this->store()->read($first), 'and the old one is gone');
        self::assertNotNull($this->store()->read($second));

        self::assertSame('hello', (string) $this->request('GET', '/admin/acme')->getBody());
        self::assertSame('hello', (string) $this->request('GET', '/admin/acme')->getBody());

        $confirm = $this->request('GET', '/logout');
        $out = $this->submit($confirm, '/logout', []);
        self::assertSame('/login', $out->getHeaderLine('Location'));
        self::assertNull($this->store()->read($second), 'signing out deletes the session');
        self::assertStringContainsString('You have signed out.', (string) $this->request('GET', '/login')->getBody(), 'the flash message reached the other server');
        self::assertSame(303, $this->request('GET', '/admin/acme')->getStatusCode());
    }

    public function testTheDatabaseKeepsOnlyHashesAndRefusesAnExpiredSession(): void
    {
        $this->servers('database');
        $this->submit($this->request('GET', '/login'), '/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD]);
        $id = $this->sessionId();

        $connection = Services::get($this->site(), Connection::class);
        $rows = $connection->createQueryBuilder()->select('*')->from(DatabaseSessionStore::TABLE)->fetchAllAssociative();
        $everything = json_encode($rows, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($id, $everything, 'a raw session id is never stored');
        self::assertContains(hash('sha256', $id), array_column($rows, 'id_hash'));

        $connection->createQueryBuilder()->update(DatabaseSessionStore::TABLE)->set('expires_at', ':past')
            ->setParameter('past', new DateTimeImmutable('-1 minute'), Types::DATETIME_IMMUTABLE)->executeStatement();
        $refused = $this->request('GET', '/admin/acme');
        self::assertSame(303, $refused->getStatusCode(), 'an expired session signs nobody in');
        self::assertStringContainsString('Max-Age=0', $refused->getHeaderLine('Set-Cookie'));
        self::assertSame(1, Services::get($this->site(), DatabaseSessionStore::class, SessionStore::class)->forgetExpired(new DateTimeImmutable()), 'and the schedule removes it');
    }

    public function testSwitchingTheStoreSignsPeopleOutCleanly(): void
    {
        $this->servers('database');
        $this->submit($this->request('GET', '/login'), '/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD]);
        self::assertSame('hello', (string) $this->request('GET', '/admin/acme')->getBody());
        $old = $this->sessionId();

        $this->servers = [$this->kernel('files')];
        $after = $this->request('GET', '/admin/acme');

        self::assertSame(303, $after->getStatusCode());
        self::assertStringStartsWith('/login', $after->getHeaderLine('Location'));
        self::assertNotSame($old, $this->cookies[SessionMiddleware::SECURE_COOKIE] ?? null, 'the old cookie is removed, or replaced by a new session');
        self::assertSame(303, $this->submit($this->request('GET', '/login'), '/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD])->getStatusCode(), 'signing in again works');
    }

    private function servers(string $store): void
    {
        if ($store === 'redis' && (!is_string(getenv('SIGHTPRESS_TEST_REDIS_DSN')) || getenv('SIGHTPRESS_TEST_REDIS_DSN') === '' || !RedisServer::available())) {
            self::markTestSkipped('Set SIGHTPRESS_TEST_REDIS_DSN to a Redis server to run the Redis sessions flow.');
        }
        $this->project = new TestProject();
        $dsn = TestDatabases::dsn('sessions', $this->project->path());
        $this->project->directory->write('config/sites.php', '<?php return ' . var_export([
            'version' => 2,
            'sites' => ['demo' => SiteFixture::entry('demo', [
                'domains' => ['example.org'],
                'db_dsn' => SiteFixture::secretBox()->encrypt($dsn),
            ])],
        ], true) . ';');
        $this->servers = [$this->kernel($store), $this->kernel($store)];

        $site = $this->site();
        $sites = Services::get($this->servers[0]->container(), SiteRepository::class);
        Services::get($this->servers[0]->container(), SiteMigrator::class)->migrate($sites->find('demo') ?? throw new \LogicException('demo'));
        Services::get($site, RbacSeeder::class)->seed();
        $user = new User('ada@example.org', 'Ada', 'Lovelace', 'UTC', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher()->hash(self::PASSWORD), new DateTimeImmutable());
        Services::get($site, UserRepository::class)->save($user);
        $roles = Services::get($site, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole('editor') ?? throw new \LogicException('editor'));
    }

    private function kernel(string $store): Kernel
    {
        $this->project->directory->write('config/tenant.php', '<?php return ' . var_export([
            'session' => ['store' => $store, 'path' => $this->project->path() . '/var/sessions'],
        ], true) . ';');
        $admin = new FakeModule('acme.admin', register: static function (ModuleRegistrar $r): void {
            $r->routes()->add(new Route('acme.admin', '/admin/acme', HelloHandler::class, middleware: [AdminAccessMiddleware::class]));
        });

        return Kernel::fromConfig(
            new TenantConfigLoader()->load($this->project->path(), [
                'APP_ENV' => 'test',
                'APP_SECRET' => SiteFixture::SECRET,
                'SESSION_REDIS_DSN' => (string) getenv('SIGHTPRESS_TEST_REDIS_DSN'),
            ]),
            [...CoreModules::candidates(), new ModuleCandidate($admin, 'acme/admin')],
        );
    }

    private function site(): ContainerInterface
    {
        $container = $this->servers[0]->container();
        $site = Services::get($container, SiteRepository::class)->find('demo') ?? throw new \LogicException('demo');

        return Services::get($container, SiteContainerFactory::class)->forSite($site);
    }

    private function store(): SessionStore
    {
        return Services::get($this->site(), SessionStore::class);
    }

    private function sessionId(): string
    {
        return $this->cookies[SessionMiddleware::SECURE_COOKIE] ?? throw new \LogicException('There is no session cookie.');
    }

    /**
     * @param array<string, string> $fields
     */
    private function submit(ResponseInterface $page, string $path, array $fields): ResponseInterface
    {
        preg_match_all('/<input type="hidden" name="(csrf_[a-z]+)" value="([^"]+)">/', (string) $page->getBody(), $hidden, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(2, count($hidden), 'the form has its CSRF token');
        foreach (array_slice($hidden, 0, 2) as [, $name, $value]) {
            $fields[$name] = html_entity_decode($value);
        }

        return $this->request('POST', $path, $fields);
    }

    /**
     * Sends the request to the next server in turn.
     *
     * @param array<string, string> $body
     */
    private function request(string $method, string $path, array $body = []): ResponseInterface
    {
        $request = new ServerRequest($method, 'https://example.org' . $path, [], null, '1.1', ['REMOTE_ADDR' => '192.0.2.21'])
            ->withCookieParams($this->cookies);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }

        $kernel = $this->servers[$this->next++ % count($this->servers)];
        $response = $kernel->handle($request);
        $kernel->terminate();
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
