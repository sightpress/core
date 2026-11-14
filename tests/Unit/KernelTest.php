<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SightPress\Core\Infrastructure\Container\CoreServices;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Tenant\Environment;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\Modules\HelloHandler;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\ExampleCommand;
use SightPress\Core\Tests\Support\Modules\ExampleModule;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;
use Psr\EventDispatcher\EventDispatcherInterface;

#[CoversClass(Kernel::class)]
#[CoversClass(CoreServices::class)]
#[UsesClass(Services::class)]
#[UsesNamespace('SightPress\Core\Http')]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesNamespace('SightPress\Core\Infrastructure')]
#[UsesNamespace('SightPress\Core\Module')]
#[UsesNamespace('SightPress\Core\Cli')]
#[UsesNamespace('SightPress\Core\Template')]
final class KernelTest extends TestCase
{
    private ?TestProject $project = null;

    /** @var array<mixed> */
    private array $server = [];

    private string $errorLog = '';

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->errorLog = (string) ini_get('error_log');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        ini_set('error_log', $this->errorLog);
        $this->project?->remove();
    }

    public function testWithoutSitesEveryUnknownPathShowsNoSiteConfigured(): void
    {
        $kernel = Kernel::boot($this->project()->path(), ['APP_ENV' => 'production']);

        $response = $kernel->handle(new ServerRequest('GET', 'http://example.org/any/path'));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('<h1>No site configured</h1>', (string) $response->getBody());
        self::assertFalse($response->hasHeader('X-Robots-Tag'));
        self::assertSame(Environment::Production, $kernel->config()->environment);
        self::assertSame('UTC', date_default_timezone_get());
    }

    public function testWithSitesAnUnknownPathIsPageNotFound(): void
    {
        $kernel = Kernel::boot($this->project(sites: [['slug' => 'demo']])->path(), ['APP_ENV' => 'dev']);

        $response = $kernel->handle(new ServerRequest('GET', '/missing', ['Accept' => 'application/json']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
    }

    public function testRoutesAnswerAndFailuresAreLogged(): void
    {
        $project = $this->project();
        $kernel = Kernel::boot($project->path(), ['APP_ENV' => 'test']);
        $kernel->app()->get('/ok', function ($request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write('fine');

            return $response;
        });
        $kernel->app()->get('/fail', fn () => throw new RuntimeException('broken'));
        $kernel->app()->get('/only-get', fn ($request, ResponseInterface $response) => $response);

        $ok = $kernel->handle(new ServerRequest('GET', '/ok'));
        $failed = $kernel->handle(new ServerRequest('GET', '/fail'));
        $wrongMethod = $kernel->handle(new ServerRequest('DELETE', '/only-get'));

        self::assertSame('fine', (string) $ok->getBody());
        self::assertSame(500, $failed->getStatusCode());
        self::assertStringContainsString('app.ERROR: GET /fail failed with 500: RuntimeException: broken', $project->log());
        self::assertSame(405, $wrongMethod->getStatusCode());
        self::assertSame('GET', $wrongMethod->getHeaderLine('Allow'));
        self::assertTrue($kernel->container()->has(\Psr\Log\LoggerInterface::class));
    }

    public function testRunSendsTheResponse(): void
    {
        $kernel = Kernel::boot($this->project()->path(), []);

        $this->expectOutputRegex('#<h1>No site configured</h1>#');

        $kernel->run(new ServerRequest('GET', '/'));
    }

    public function testServeAnswersTheCurrentRequest(): void
    {
        $project = $this->project();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/hello';
        $_SERVER['HTTP_HOST'] = 'example.org';

        $this->expectOutputRegex('#<title>No site configured - SightPress</title>#');

        Kernel::serve($project->path());
    }

    public function testServeStillAnswersWhenBootingFails(): void
    {
        $project = $this->project(['timezone' => 'Nowhere/Land']);
        $log = $project->path() . '/php-error.log';
        ini_set('error_log', $log);

        $this->expectOutputRegex('#<h1>Sorry, there is a problem with the service</h1>#');

        Kernel::serve($project->path());

        self::assertStringContainsString(
            'SightPress could not start: SightPress\Core\Tenant\TenantConfigException: Tenant setting "timezone"',
            (string) file_get_contents($log),
        );
    }

    public function testBootReadsTheProcessEnvironmentByDefault(): void
    {
        $_SERVER['APP_DEBUG'] = '1';

        $kernel = Kernel::boot($this->project()->path());

        self::assertTrue($kernel->config()->debug);
    }

    public function testTheKernelBootsThroughTheModuleLoader(): void
    {
        $config = new TenantConfigLoader()->load($this->project(sites: [['slug' => 'demo']])->path(), []);
        $module = new FakeModule('acme.hello', register: static function (ModuleRegistrar $r): void {
            $r->routes()->add(new Route('hello.show', '/hello', HelloHandler::class));
            $r->commands()->add(ExampleCommand::class);
        });

        $kernel = Kernel::fromConfig($config, [new ModuleCandidate($module, 'acme/hello', '*')]);
        $response = $kernel->handle(new ServerRequest('GET', '/hello'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['acme.hello'], $kernel->modules()->modules());
        self::assertTrue($kernel->console()->has('example:echo'));
        self::assertTrue($kernel->container()->has(EventDispatcherInterface::class));
    }

    public function testModulesThatAreOffRegisterNothing(): void
    {
        $config = new TenantConfigLoader()->load($this->project(sites: [['slug' => 'demo']])->path(), []);
        $module = new FakeModule('acme.hello', register: static function (ModuleRegistrar $r): void {
            $r->routes()->add(new Route('hello.show', '/hello', HelloHandler::class));
        });

        $kernel = Kernel::fromConfig(
            $config,
            [new ModuleCandidate($module, 'acme/hello')],
            new ModuleSelection(['acme.hello' => false]),
        );

        self::assertSame(404, $kernel->handle(new ServerRequest('GET', '/hello'))->getStatusCode());
        self::assertSame([], $kernel->modules()->modules());
    }

    public function testBootDiscoversModulesInstalledWithComposer(): void
    {
        $project = $this->project();
        $project->directory->write('vendor/composer/installed.json', (string) json_encode(['packages' => [[
            'name' => 'acme/example',
            'require' => ['sightpress/module-interface' => '*'],
            'extra' => ['sightpress' => ['module' => ExampleModule::class]],
        ]]]));

        $kernel = Kernel::boot($project->path(), []);

        self::assertSame(['acme.example'], $kernel->modules()->modules());
        self::assertNotSame('', Kernel::contractVersion());
    }

    public function testAModuleProblemStopsTheBoot(): void
    {
        $config = new TenantConfigLoader()->load($this->project()->path(), []);

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module "acme.hello" requires module "calendar", which is not installed.');

        Kernel::fromConfig($config, [new ModuleCandidate(new FakeModule('acme.hello', ['calendar' => '*']), 'acme/hello')]);
    }

    /**
     * @param array<string, mixed>       $tenant
     * @param list<array<string, mixed>> $sites
     */
    private function project(array $tenant = [], array $sites = []): TestProject
    {
        return $this->project = new TestProject($tenant, $sites);
    }
}
