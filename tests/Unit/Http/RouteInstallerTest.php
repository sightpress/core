<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http;

use DI\Container;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Http\RouteInstaller;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;
use Slim\App;

#[CoversClass(RouteInstaller::class)]
#[UsesNamespace('SightPress\Core\Module')]
#[UsesClass(\SightPress\Core\Http\SiteScopedHandler::class)]
#[UsesClass(\SightPress\Core\Http\SiteScopedMiddleware::class)]
#[UsesClass(\SightPress\Core\Infrastructure\Container\Services::class)]
#[UsesClass(\SightPress\Core\Tenant\SiteContext::class)]
#[UsesClass(\SightPress\Core\Http\ModuleGate::class)]
#[UsesClass(\SightPress\Core\Http\RoutePermissionMiddleware::class)]
#[UsesClass(Actor::class)]
final class RouteInstallerTest extends TestCase
{
    public function testModuleRoutesAnswerThroughTheirMiddlewareInOrder(): void
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme', register: static function (ModuleRegistrar $r): void {
                $r->routes()->add(new Route(
                    'acme.hello',
                    '/hello/{name}',
                    HelloHandler::class,
                    ['GET', 'POST'],
                    [FirstMiddleware::class, SecondMiddleware::class],
                ));
            }), 'sightpress/core'),
        ])->resolve(new ModuleSelection()));
        $factory = new Psr17Factory();
        $app = new App($factory, new Container());
        $app->addRoutingMiddleware();

        new RouteInstaller(new Container())->install($app, $registry);
        $response = $app->handle(new ServerRequest('POST', '/hello/ada'));

        self::assertSame('first,second,hello ada', (string) $response->getBody());
        self::assertSame('/hello/grace', $app->getRouteCollector()->getRouteParser()->urlFor('acme.hello', ['name' => 'grace']));
    }

    public function testARoutePermissionIsCheckedBeforeTheRouteMiddleware(): void
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme', register: static function (ModuleRegistrar $r): void {
                $r->routes()->add(new Route('acme.hello', '/hello/{name}', HelloHandler::class, middleware: [FirstMiddleware::class], permission: 'hello.say'));
            }), 'sightpress/core'),
        ])->resolve(new ModuleSelection()));
        $app = new App(new Psr17Factory(), new Container());
        $app->addRoutingMiddleware();
        $tenant = new Container();
        $tenant->set(\Psr\Http\Message\ResponseFactoryInterface::class, new Psr17Factory());

        new RouteInstaller($tenant)->install($app, $registry);

        self::assertSame(303, $app->handle(new ServerRequest('GET', '/hello/ada'))->getStatusCode(), 'signed out: to the sign-in page');
        $allowed = new ServerRequest('GET', '/hello/ada')->withAttribute(Actor::class, new Actor(1, ['hello.say']));
        self::assertSame('first,hello ada', (string) $app->handle($allowed)->getBody());
    }

    public function testRoutesThatCatchEveryAddressComeLastWhateverTheModuleOrder(): void
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme', register: static function (ModuleRegistrar $r): void {
                $r->routes()->add(new Route('acme.pages', '/{name:.+}', HelloHandler::class));
                $r->routes()->add(new Route('acme.hello', '/hello/{name}', HelloHandler::class, middleware: [FirstMiddleware::class]));
                $r->routes()->add(new Route('acme.localised', '/{name:[a-z]{2}(?:-[a-z]{2})?}/hello', HelloHandler::class, middleware: [FirstMiddleware::class]));
            }), 'sightpress/core'),
        ])->resolve(new ModuleSelection()));
        $app = new App(new Psr17Factory(), new Container());
        $app->addRoutingMiddleware();

        new RouteInstaller(new Container())->install($app, $registry);

        self::assertSame('first,hello ada', (string) $app->handle(new ServerRequest('GET', '/hello/ada'))->getBody());
        self::assertSame('hello about/team', (string) $app->handle(new ServerRequest('GET', '/about/team'))->getBody());
        self::assertSame('first,hello pt-pt', (string) $app->handle(new ServerRequest('GET', '/pt-pt/hello'))->getBody(), 'more than a placeholder: before the catch-all');
    }
}

final class HelloHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $trail = $request->getAttribute('trail');
        $route = \Slim\Routing\RouteContext::fromRequest($request)->getRoute();
        $response = new Psr17Factory()->createResponse();
        $response->getBody()->write(sprintf('%shello %s', is_string($trail) ? $trail : '', $route?->getArgument('name') ?? ''));

        return $response;
    }
}

final class FirstMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute('trail', 'first,'));
    }
}

final class SecondMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $trail = $request->getAttribute('trail');

        return $handler->handle($request->withAttribute('trail', (is_string($trail) ? $trail : '') . 'second,'));
    }
}
