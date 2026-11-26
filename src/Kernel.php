<?php

declare(strict_types=1);

namespace SightPress\Core;

use Closure;
use Composer\InstalledVersions;
use DI\ContainerBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use SightPress\Core\Cli\ConsoleApplicationFactory;
use SightPress\Core\Http\AfterResponse;
use SightPress\Core\Http\Csrf\CsrfMiddleware;
use SightPress\Core\Http\Security\StepUpMiddleware;
use SightPress\Core\Http\Error\ErrorCatalogue;
use SightPress\Core\Http\Error\ErrorHandlingMiddleware;
use SightPress\Core\Http\Error\HtmlErrorRenderer;
use SightPress\Core\Http\Error\NoSiteConfiguredMiddleware;
use SightPress\Core\Infrastructure\Container\CoreServices;
use SightPress\Core\Http\RateLimit\RateLimitMiddleware;
use SightPress\Core\Http\RequestAnsweredMiddleware;
use SightPress\Core\Http\RouteInstaller;
use SightPress\Core\Http\SiteResolverMiddleware;
use SightPress\Core\Http\Security\AuthenticationMiddleware;
use SightPress\Core\Http\Security\MembersOnlyMiddleware;
use SightPress\Core\Http\Security\SecurityHeadersMiddleware;
use SightPress\Core\Http\Session\SessionMiddleware;
use SightPress\Core\Http\Upload\RequestOverflowMiddleware;
use SightPress\Core\Http\Upload\UploadProbeMiddleware;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Module\ComposerModuleDiscovery;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleDiscovery;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModulePolicy;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Module\ModuleServices;
use SightPress\Core\Module\UnavailableModuleLog;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tenant\TenantConfigLoader;
use Slim\App;
use Slim\ResponseEmitter;
use Symfony\Component\Console\Application;
use Throwable;

/**
 * Boots SightPress for one tenant (one install): reads the tenant config, loads the modules (core's own and those
 * installed with Composer), builds the container and the HTTP application from what they register, and answers
 * requests. Everything beyond error handling comes from modules (ADR-0009).
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
        private ModuleRegistry $modules,
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

    /**
     * @param list<ModuleCandidate>|null $modules   null discovers core's modules and those installed with Composer
     * @param ModuleSelection|null       $selection which modules the tenant registers; null registers every installed
     *                                              module except those `modules.locked` turns off, and each site then
     *                                              turns its optional modules on or off (ADR-0034)
     */
    public static function fromConfig(
        TenantConfig $config,
        ?array $modules = null,
        ?ModuleSelection $selection = null,
    ): self {
        $cacheDir = $config->debug ? null : $config->cacheDir;
        $discovery = $modules === null ? ComposerModuleDiscovery::forProject($config->projectDir, $cacheDir)->discover() : new ModuleDiscovery();
        // An external module with a problem is left out as unavailable; only a broken core module stops the tenant
        // (ADR-0065).
        $catalogue = new ModuleLoader(self::contractVersion())->catalogue(
            $modules ?? [...CoreModules::candidates(), ...$discovery->candidates],
            $discovery->unavailable,
        );
        $policy = ModulePolicy::fromConfig($catalogue, $config);
        $registry = ModuleRegistry::register($catalogue->resolve($selection ?? $policy->tenantSelection()));
        if ($registry->failures() !== []) {
            $catalogue = $catalogue->without($registry->failures());
            $policy = ModulePolicy::fromConfig($catalogue, $config);
        }

        $core = CoreServices::definitions($config, $registry, $policy);
        $container = new ContainerBuilder()
            ->useAutowiring(true)
            ->useAttributes(false)
            ->addDefinitions($core)
            ->addDefinitions(ModuleServices::definitions($registry, [...array_keys($core), ...SiteContainerFactory::SITE_SERVICES]))
            ->build();

        $responseFactory = Services::get($container, ResponseFactoryInterface::class);

        /** @var App<ContainerInterface> $app */
        $app = new App($responseFactory, $container);
        // Middleware added last runs first. Two-step confirmation (N-6) holds critical changes after the CSRF check.
        // The site is resolved from the host before anything else touches the
        // request. Routing comes before the CSRF check, so an unknown address or method answers 404 or 405 rather
        // than 403. Members-only sites send anonymous visitors to sign in once the visitor is known. A request larger
        // than PHP reads has lost its CSRF token, so it is answered "too large" before the CSRF check. Modules hear
        // about each answered request after the response is sent, once the visitor is known (ADR-0037).
        $app->add(StepUpMiddleware::class);
        $app->add(CsrfMiddleware::class);
        $app->add(RequestOverflowMiddleware::class);
        $app->add(MembersOnlyMiddleware::class);
        $app->add(RequestAnsweredMiddleware::class);
        $app->add(AuthenticationMiddleware::class);
        $app->addRoutingMiddleware();
        $app->add(SessionMiddleware::class);
        $app->add(RateLimitMiddleware::class);
        $app->add(SiteResolverMiddleware::class);
        $app->add(NoSiteConfiguredMiddleware::class);
        // A signed upload probe measures the web server alone, so nothing else may refuse it first.
        $app->add(UploadProbeMiddleware::class);
        $app->add(ErrorHandlingMiddleware::class);
        $app->add(SecurityHeadersMiddleware::class);
        new RouteInstaller($container, $policy)->install($app, $registry);
        if ($catalogue->unavailable() !== []) {
            UnavailableModuleLog::inCacheFolder(Services::get($container, LoggerInterface::class), $cacheDir)->report($catalogue->unavailable());
        }

        return new self($config, $container, $app, $registry);
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
        // Let the visitor go before the work that waits for the response, where the server can (PHP-FPM).
        $this->terminate(function_exists('fastcgi_finish_request') ? fastcgi_finish_request(...) : null);
    }

    /**
     * Does the work that waits until the response has been sent ({@see AfterResponse}), such as telling modules
     * about the request. {@see run()} calls it; whoever calls {@see handle()} directly calls it after sending.
     *
     * @param Closure(): mixed|null $release ends the response for the client first
     */
    public function terminate(?Closure $release = null): void
    {
        Services::get($this->container, AfterResponse::class)->run($release);
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

    /**
     * What the modules that are on registered.
     */
    public function modules(): ModuleRegistry
    {
        return $this->modules;
    }

    /**
     * The `bin/sightpress` console with every module's commands.
     */
    public function console(): Application
    {
        return new ConsoleApplicationFactory($this->container, $this->modules)
            ->create(InstalledVersions::getPrettyVersion('sightpress/core') ?? 'UNKNOWN');
    }

    /**
     * The installed version of `sightpress/module-interface`, which external modules declare they work with.
     */
    public static function contractVersion(): string
    {
        return InstalledVersions::getPrettyVersion(ComposerModuleDiscovery::CONTRACT_PACKAGE) ?? 'dev-unknown';
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
                if (is_string($name) && is_string($value) && (str_starts_with($name, 'APP_') || $name === 'MAILER_DSN')) {
                    $variables[$name] ??= $value;
                }
            }
        }

        return $variables;
    }
}
