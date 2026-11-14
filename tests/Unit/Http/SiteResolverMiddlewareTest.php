<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http;

use DI\Container;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Error\HttpServiceUnavailableException;
use SightPress\Core\Http\SiteResolverMiddleware;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Tenant\SiteContext;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\CallbackHandler;
use SightPress\Core\Tests\Support\SiteFixture;
use Slim\Exception\HttpGoneException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

#[CoversClass(SiteResolverMiddleware::class)]
#[UsesClass(HttpServiceUnavailableException::class)]
#[UsesClass(SecretBox::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
final class SiteResolverMiddlewareTest extends TestCase
{
    public function testAnEnabledSiteGetsItsContext(): void
    {
        $seen = null;
        $response = $this->middleware()->process(
            new ServerRequest('GET', 'https://Demo.Example/about'),
            new CallbackHandler(static function (ServerRequestInterface $request) use (&$seen) {
                $seen = SiteContext::fromRequest($request);

                return new Psr17Factory()->createResponse(204);
            }),
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertInstanceOf(SiteContext::class, $seen);
        self::assertSame('demo', $seen->site->slug);
        self::assertSame($seen->site, $seen->container->get(Site::class));
    }

    /**
     * @return iterable<string, array{string, class-string<Throwable>}>
     */
    public static function unavailable(): iterable
    {
        yield 'unknown host' => ['http://shop.example/', HttpNotFoundException::class];
        yield 'no host' => ['/about', HttpNotFoundException::class];
        yield 'deleted' => ['http://old.example/', HttpNotFoundException::class];
        yield 'archived' => ['http://archive.example/', HttpGoneException::class];
        yield 'disabled' => ['http://paused.example/', HttpServiceUnavailableException::class];
    }

    /**
     * @param class-string<Throwable> $expected
     */
    #[DataProvider('unavailable')]
    public function testOtherSitesDoNotReachTheApplication(string $uri, string $expected): void
    {
        try {
            $this->middleware()->process(
                new ServerRequest('GET', $uri),
                new CallbackHandler(static fn () => self::fail('The request reached the application.')),
            );
            self::fail('Nothing was thrown.');
        } catch (Throwable $exception) {
            self::assertInstanceOf($expected, $exception);
            if ($exception instanceof HttpServiceUnavailableException) {
                self::assertSame(SiteResolverMiddleware::RETRY_AFTER, $exception->retryAfter());
            }
        }
    }

    private function middleware(): SiteResolverMiddleware
    {
        $sites = new class () implements SiteRepository {
            /** @var array<non-empty-string, Site> */
            private array $sites;

            public function __construct()
            {
                $this->sites = [
                    'demo' => SiteFixture::site('demo', ['demo.example']),
                    'old' => SiteFixture::site('old', ['old.example'], SiteStatus::Deleted),
                    'archive' => SiteFixture::site('archive', ['archive.example'], SiteStatus::Archived),
                    'paused' => SiteFixture::site('paused', ['paused.example'], SiteStatus::Disabled),
                ];
            }

            public function all(): array
            {
                return $this->sites;
            }

            public function find(string $slug): ?Site
            {
                return $this->sites[$slug] ?? null;
            }

            public function findByDomain(string $domain): ?Site
            {
                foreach ($this->sites as $site) {
                    if (in_array(strtolower($domain), $site->domains, true)) {
                        return $site;
                    }
                }

                return null;
            }

            public function save(Site $site): void
            {
            }

            public function remove(string $slug): void
            {
            }
        };

        return new SiteResolverMiddleware(
            $sites,
            new SiteContainerFactory(new Container(), SiteFixture::secretBox(), TenantConfig::fromArray([], '/srv/app')),
        );
    }
}
