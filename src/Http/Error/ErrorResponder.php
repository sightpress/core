<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Error;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\ViewerPreferences;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContext;
use Slim\Exception\HttpException;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use SightPress\Core\Http\AcceptNegotiator;
use Throwable;

/**
 * Builds the response for an exception: an accessible HTML page, or Problem Details JSON when the client prefers
 * JSON. Server errors (5xx) are logged with the exception; technical details are only shown in debug mode.
 */
final readonly class ErrorResponder
{
    public function __construct(
        private ErrorCatalogue $catalogue,
        private HtmlErrorRenderer $html,
        private JsonErrorRenderer $json,
        private AcceptNegotiator $negotiator,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private LoggerInterface $logger,
        private bool $debug = false,
        private bool $noindex = false,
        private ?Translations $translations = null,
        private string $defaultLocale = Translations::SOURCE_LOCALE,
    ) {
    }

    public function respond(ServerRequestInterface $request, Throwable $exception): ResponseInterface
    {
        $page = $this->catalogue->pageFor($exception);

        if ($page->status >= 500) {
            $this->logger->error('{method} {path} failed with {status}: {class}: {message}', [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'status' => $page->status,
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }

        $details = $this->debug ? $this->details($exception) : null;
        $response = $this->responseFactory->createResponse($page->status)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer');

        if ($this->negotiator->prefersJson($request)) {
            $response = $response
                ->withHeader('Content-Type', JsonErrorRenderer::CONTENT_TYPE)
                ->withBody($this->streamFactory->createStream($this->json->render($page, $details)));
        } else {
            $response = $response
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Content-Security-Policy', $this->html->contentSecurityPolicy())
                ->withBody($this->streamFactory->createStream($this->html->render($page, $details, $this->translations, $this->locale($exception instanceof HttpException ? $exception->getRequest() : $request))));
        }

        if ($this->noindex) {
            $response = $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        foreach ($page->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * The language of an error page: the signed-in person's, else the language part of the address on the site
     * (`/pt-pt/…`), else the site's default, else the admin default.
     */
    private function locale(ServerRequestInterface $request): string
    {
        $own = $request->getAttribute(ViewerPreferences::class);
        if ($own instanceof ViewerPreferences && $own->locale !== null && $own->locale !== '') {
            return $own->locale;
        }
        $context = SiteContext::fromRequest($request);
        if ($context === null) {
            return $this->defaultLocale;
        }
        $site = $context->container->has(Site::class) ? $context->container->get(Site::class) : $context->site;
        $site = $site instanceof Site ? $site : $context->site;
        $first = strtolower(explode('/', ltrim($request->getUri()->getPath(), '/'))[0]);
        foreach ($site->locales as $locale) {
            if (strtolower($locale) === $first) {
                return $locale;
            }
        }

        return $site->defaultLocale;
    }

    private function details(Throwable $exception): string
    {
        $details = [];
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $details[] = sprintf(
                "%s: %s\nin %s:%d\n%s",
                $current::class,
                $current->getMessage(),
                $current->getFile(),
                $current->getLine(),
                $current->getTraceAsString(),
            );
        }

        return implode("\n\nCaused by ", $details);
    }
}
