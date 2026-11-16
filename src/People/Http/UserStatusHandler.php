<?php

declare(strict_types=1);

namespace SightPress\Core\People\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\User\Contract\AccountRoster;

/**
 * `POST /admin/users/{id}/status`: deactivates the person (they can't sign in, their content stays), or reactivates
 * them when they were deactivated. Nobody deactivates themselves. Needs `user.manage`.
 */
final readonly class UserStatusHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdminScreens $screens,
        private AccountRoster $accounts,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$actor, $account] = UserHandler::target($request, $this->accounts);
        $path = UserHandler::path($account->id);
        if ($actor->userId === $account->id) {
            return $this->screens->redirect($request, $path, 'You can’t deactivate your own account.');
        }

        if ($account->isDeactivated()) {
            $this->accounts->reactivate($account->id, $actor->userId);
            $message = sprintf('%s has been reactivated and can sign in again.', $account->effectiveName);
        } else {
            $this->accounts->deactivate($account->id, $actor->userId);
            $message = sprintf('%s has been deactivated and can no longer sign in. Their pages stay.', $account->effectiveName);
        }

        return $this->screens->redirect($request, $path, $message);
    }
}
