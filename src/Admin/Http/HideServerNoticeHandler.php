<?php

declare(strict_types=1);

namespace SightPress\Core\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Upload\RequestTooLargeException;
use SightPress\Core\Http\Upload\ServerNotice;
use SightPress\Core\Rbac\Contract\Actor;
use Slim\Exception\HttpForbiddenException;

/**
 * `POST /admin/server-notice/hide`: hides the notice about the server's limits for this site until its result changes,
 * and goes back to the admin screen it was on. Needs `settings.manage`.
 */
final readonly class HideServerNoticeHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/server-notice/hide';

    public function __construct(
        private AdminScreens $screens,
        private ServerNotice $notice,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!Actor::fromRequest($request)->has(SettingsHandler::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $this->notice->hide();
        $back = RequestTooLargeException::backFrom($request);

        return $this->screens->redirect(
            $request,
            $back !== null && ($back === '/admin' || str_starts_with($back, '/admin/') || str_starts_with($back, '/admin?')) ? $back : '/admin',
            'The notice is hidden until the result changes.',
        );
    }
}
