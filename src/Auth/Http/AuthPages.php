<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\Site;

/**
 * Renders the sign-in pages with the site's name and any waiting flash messages, and answers with redirects.
 * Responses are never cached.
 */
final readonly class AuthPages
{
    public function __construct(
        private TemplateRenderer $templates,
        private ResponseFactoryInterface $responses,
        private Site $site,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function page(ServerRequestInterface $request, string $template, array $context = [], int $status = 200): ResponseInterface
    {
        return $this->templates
            ->response($request, '@auth/' . $template, [
                'site_name' => $this->site->name,
                'messages' => Flash::take(Session::fromRequest($request)),
            ] + $context, $status)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * A 303 to a path of this site, so the browser follows it with GET.
     */
    public function redirect(string $path): ResponseInterface
    {
        return $this->responses->createResponse(303)
            ->withHeader('Location', $path)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The form fields from the request body.
     *
     * @return array<mixed>
     */
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    public static function query(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * "You have tried to … too many times", with the wait in whole minutes.
     *
     * @param positive-int $retryAfter seconds
     */
    public static function tooManyAttempts(string $what, int $retryAfter): string
    {
        $minutes = (int) ceil($retryAfter / 60);

        return sprintf(
            'You have %s too many times. Wait %d %s and try again.',
            $what,
            $minutes,
            $minutes === 1 ? 'minute' : 'minutes',
        );
    }
}
