<?php

declare(strict_types=1);

namespace SightPress\Core\People\Http;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Template\Message;
use SightPress\Core\User\Contract\AccountPhones;
use SightPress\Core\User\Contract\AccountRoster;
use SightPress\Module\Event\AccountChanged;

/**
 * `POST /admin/users/{id}/phone` (N-6): someone with `user.manage` removes another person's mobile number, for
 * example after they lost their phone, so they can add a new one. A critical action itself while two-step
 * confirmation is on. Audited.
 */
final readonly class UserPhoneHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdminScreens $screens,
        private AccountRoster $accounts,
        private AccountPhones $phones,
        private EventDispatcherInterface $events,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$actor, $account] = UserHandler::target($request, $this->accounts);
        $path = UserHandler::path($account->id);
        if ($actor->userId === $account->id) {
            return $this->screens->redirect($request, $path, 'Remove your own number on My account.');
        }
        if (!$this->phones->removePhone($account->id)) {
            return $this->screens->redirect($request, $path, new Message('{effective_name} has no mobile number.', ['effective_name' => $account->effectiveName]));
        }
        $this->events->dispatch(new AccountChanged($account->id, $actor->userId, ['phone']));

        return $this->screens->redirect($request, $path, new Message('The mobile number of {effective_name} is removed. They can add a new one on My account.', ['effective_name' => $account->effectiveName]));
    }
}
