<?php

declare(strict_types=1);

namespace SightPress\Core;

use DI\ContainerBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Error\ErrorCatalogue;
use SightPress\Core\Http\Error\ErrorHandlingMiddleware;
use SightPress\Core\Http\Error\HtmlErrorRenderer;
use SightPress\Core\Http\Error\NoSiteConfiguredMiddleware;
use SightPress\Core\Infrastructure\Container\CoreServices;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tenant\TenantConfigLoader;
use Slim\App;
use Slim\ResponseEmitter;
use Throwable;

/**
 * Boots SightPress for one tenant (one install): reads the tenant config, builds the tenant container and the HTTP
 * application, and answers requests.
 *
 * A project's `public/index.php` only needs `Kernel::serve(dirname(__DIR__));`.
 */
final readonly class Kernel
{
    /**
     * @param App<ContainerInterface> $app
     */
    private function __construct(
        private TenantConfig $config,
        private ContainerInterface $container,
        private App $app,
    ) {
    }

    /**
     * @param array<string, string>|null $environment environment variables; null reads them from the process
     */
    public static function boot(string $projectDir, ?array $environment = null): self
    {
        date_default_timezone_set('UTC');

        return self::fromConfig(new TenantConfigLoader()->load($projectDir, $environment ?? self::environment()));
    }

    public static function fromConfig(TenantConfig $config): self
    {
        $container = new ContainerBuilder()
            ->useAutowiring(true)
            ->useAttributes(false)
            ->addDefinitions(CoreServices::definitions($config))
            ->build();

        $responseFactory = Services::get($container, ResponseFactoryInterface::class);

        /** @var App<ContainerInterface> $app */
        $app = new App($responseFactory, $container);
        // Middleware added last runs first.
        $app->addRoutingMiddleware();
        $app->add(NoSiteConfiguredMiddleware::class);
        $app->add(ErrorHandlingMiddleware::class);

        return new self($config, $container, $app);
    }

    /**
     * Boots the kernel and answers the current request. If booting fails (for example an invalid tenant config),
     * it still answers with an accessible 500 page and writes the reason to the PHP error log.
     */
    public static function serve(string $projectDir): void
    {
        try {
            $kernel = self::boot($projectDir);
        } catch (Throwable $exception) {
            error_log(sprintf('SightPress could not start: %s: %s', $exception::class, $exception->getMessage()));
            new ResponseEmitter()->emit(self::bootFailure());

            return;
        }

        $kernel->run();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->app->handle($request);
    }

    /**
     * Answers a request (by default the current one, built from the PHP globals) and sends the response.
     */
    public function run(?ServerRequestInterface $request = null): void
    {
        if ($request === null) {
            $factory = new Psr17Factory();
            $request = new ServerRequestCreator($factory, $factory, $factory, $factory)->fromGlobals();
        }

        new ResponseEmitter()->emit($this->handle($request));
    }

    public function config(): TenantConfig
    {
        return $this->config;
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * @return App<ContainerInterface>
     */
    public function app(): App
    {
        return $this->app;
    }

    private static function bootFailure(): ResponseInterface
    {
        $renderer = new HtmlErrorRenderer();
        $factory = new Psr17Factory();
        $page = new ErrorCatalogue()->pageFor(new \RuntimeException());

        return $factory->createResponse($page->status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Security-Policy', $renderer->contentSecurityPolicy())
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($factory->createStream($renderer->render($page)));
    }

    /**
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $variables = getenv();
        foreach ([$_ENV, $_SERVER] as $source) {
            foreach ($source as $name => $value) {
                if (is_string($name) && is_string($value) && str_starts_with($name, 'APP_')) {
                    $variables[$name] ??= $value;
                }
            }
        }

        return $variables;
    }
}
