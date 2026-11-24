<?php

declare(strict_types=1);

namespace SightPress\Core\User\Http;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Module\Event\AccountChanged;
use SightPress\Core\User\UserRepository;
use Slim\Exception\HttpForbiddenException;

/**
 * `POST /account/preferences`: how the admin panel is shown to the signed-in person — less motion and larger text.
 * Only for people who can open the admin panel (`admin.access`); the change is audited ({@see AccountChanged}).
 */
final readonly class AccountPreferencesHandler implements RequestHandlerInterface
{
    public const string PATH = '/account/preferences';

    public function __construct(
        private AccountPages $pages,
        private FormValidator $validator,
        private UserRepository $users,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->pages->user($request);
        if ($user === null) {
            return $this->pages->signIn();
        }
        if (!Actor::fromRequest($request)->has(AccountPages::ADMIN_ACCESS)) {
            throw new HttpForbiddenException($request, 'Only people who use the admin panel have admin preferences.');
        }

        $body = $request->getParsedBody();
        $form = $this->validator->submit(AccountPages::adminPreferences($user), is_array($body) ? $body : []);
        $reducedMotion = $form->text('reduced_motion') === '1';
        $largerText = $form->text('larger_text') === '1';
        if ($reducedMotion === $user->adminReducedMotion() && $largerText === $user->adminLargerText()) {
            return $this->pages->redirect($request, 'You didn’t change anything.');
        }

        $user->changeAdminPreferences($reducedMotion, $largerText, $this->clock->now());
        $this->users->save($user);
        $this->events->dispatch(new AccountChanged((int) $user->id(), $user->id(), ['admin_preferences']));

        return $this->pages->redirect($request, 'Your admin preferences have been saved.');
    }
}
