<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use SightPress\Core\Template\Message;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\Accounts;

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
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * A 303 to a path of this site, so the browser follows it with GET.
     */
    public function redirect(string $path): ResponseInterface
    {
        return $this->responses->createResponse(303)
            ->withHeader('Location', $path)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * The account signed in to this site, or null for a visitor.
     */
    public static function signedIn(ServerRequestInterface $request, Accounts $accounts): ?Account
    {
        $id = Actor::fromRequest($request)->userId;

        return $id === null ? null : $accounts->findAccount($id);
    }

    /**
     * Sends a visitor to sign in, and back to this page afterwards.
     */
    public function signInFirst(string $return): ResponseInterface
    {
        return $this->redirect('/login?' . http_build_query(['return' => $return]));
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
     * @param 'tried to sign in'|'tried a code'|'asked for a code'|'typed your password' $what
     * @param positive-int                                                                $retryAfter seconds
     */
    public static function tooManyAttempts(string $what, int $retryAfter): Message
    {
        $minutes = ['minutes' => (int) ceil($retryAfter / 60)];

        return match ($what) {
            'tried to sign in' => new Message('You have tried to sign in too many times. Wait {minutes, plural, one {# minute} other {# minutes}} and try again.', $minutes),
            'tried a code' => new Message('You have tried a code too many times. Wait {minutes, plural, one {# minute} other {# minutes}} and try again.', $minutes),
            'asked for a code' => new Message('You have asked for a code too many times. Wait {minutes, plural, one {# minute} other {# minutes}} and try again.', $minutes),
            'typed your password' => new Message('You have typed a wrong password too many times. Wait {minutes, plural, one {# minute} other {# minutes}} and try again.', $minutes),
        };
    }
}
