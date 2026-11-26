<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\StepUp;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\Actor;

/**
 * Stops a critical action until the person has typed a code (N-6): while two-step confirmation is on, a signed-in
 * person's change to a critical route with no confirmation in the last 10 minutes of this session writes nothing. The
 * form is kept ({@see StepUpSession::hold()}) and they go to *Confirm it's you*. The console is never stopped.
 */
final readonly class StepUpGate
{
    public function __construct(
        private TwoStep $twoStep,
        private CriticalActions $actions,
        private ResponseFactoryInterface $responses,
        private ClockInterface $clock,
    ) {
    }

    public function needsConfirmation(ServerRequestInterface $request, ?string $route, bool $flagged): bool
    {
        return Actor::fromRequest($request)->isSignedIn()
            && $this->twoStep->isOn()
            && $this->actions->isCritical($route, $flagged, $request)
            && !StepUpSession::isConfirmed(Session::fromRequest($request), $this->clock->now()->getTimestamp());
    }

    public function hold(ServerRequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $action = $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());
        $referrer = parse_url($request->getHeaderLine('Referer'));
        $back = is_array($referrer) && ($referrer['host'] ?? null) === $uri->getHost() && isset($referrer['path'])
            ? $referrer['path'] . (isset($referrer['query']) ? '?' . $referrer['query'] : '')
            : $uri->getPath();
        $body = $request->getParsedBody();
        StepUpSession::hold(Session::fromRequest($request), $action, $back, is_array($body) ? $body : [], $request->getUploadedFiles() !== []);

        return $this->responses->createResponse(303)
            ->withHeader('Location', ConfirmPaths::CONFIRM)
            ->withHeader('Cache-Control', 'no-store');
    }
}
