<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\StepUp;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\Http\AuthPages;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Template\Message;

/**
 * After the code (`/confirm/continue`, N-6): the kept form, ready to send again with one button ("Make the change"),
 * or a link back to it. Passwords and files were never kept: the page says to type or choose them again.
 */
final readonly class ContinueHandler implements RequestHandlerInterface
{
    public function __construct(
        private AuthPages $pages,
        private ClockInterface $clock,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = Session::fromRequest($request);
        $held = StepUpSession::held($session);
        if ($held === null || !StepUpSession::isConfirmed($session, $this->clock->now()->getTimestamp())) {
            return $this->pages->redirect($held === null ? '/account' : ConfirmPaths::CONFIRM);
        }
        StepUpSession::release($session);
        $fields = [];
        foreach ($held['fields'] as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $one) {
                $fields[] = ['name' => is_array($value) ? $name . '[]' : $name, 'value' => $one];
            }
        }

        return $this->pages->page($request, 'confirm-continue.html.twig', [
            'title' => new Message('You confirmed it’s you'),
            'action' => $held['action'],
            'back' => $held['back'],
            'fields' => $fields,
            'left_out' => $held['left_out'],
            'minutes' => intdiv(StepUpSession::WINDOW, 60),
        ]);
    }
}
