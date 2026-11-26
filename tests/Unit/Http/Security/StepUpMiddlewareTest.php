<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Security;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Security\StepUpMiddleware;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Tenant\SiteContext;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\CallbackHandler;
use SightPress\Core\Tests\Support\SiteFixture;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;

#[CoversClass(StepUpMiddleware::class)]
#[UsesNamespace('SightPress\Core')]
final class StepUpMiddlewareTest extends TestCase
{
    public function testASiteWithoutTheSignInModuleIsLeftAlone(): void
    {
        $container = new ArrayContainer([ModuleRegistry::class => ModuleRegistry::register([])]);
        $request = new ServerRequest('POST', 'https://demo.example/admin/settings')
            ->withAttribute(SiteContext::class, new SiteContext(SiteFixture::site(), $container))
            ->withAttribute(RouteContext::ROUTE, self::createStub(RouteInterface::class));
        $handled = false;

        $response = new StepUpMiddleware()->process($request, new CallbackHandler(static function () use (&$handled): Response {
            $handled = true;

            return new Response(204);
        }));

        self::assertTrue($handled);
        self::assertSame(204, $response->getStatusCode());
    }
}
