<?php

declare(strict_types=1);

namespace SightPress\Core\Admin;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Navigation\AdminNavigation;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\Accounts;

final readonly class AdminShell implements AdminScreens
{
    public function __construct(
        private TemplateRenderer $templates,
        private ResponseFactoryInterface $responses,
        private AdminNavigation $navigation,
        private Accounts $accounts,
        private Site $site,
    ) {
    }

    public function render(ServerRequestInterface $request, string $template, array $context = [], int $status = 200): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        $form = $context['form'] ?? null;
        $account = $actor->userId === null ? null : $this->accounts->findAccount($actor->userId);

        return $this->templates
            ->response($request, $template, $context + [
                'site_name' => $this->site->name,
                'messages' => Flash::take(Session::fromRequest($request)),
                'admin_nav' => $this->navigation->for($actor->permissions(), $request->getUri()->getPath()),
                'admin_user_name' => $account?->effectiveName(),
                'error_count' => $form instanceof Form ? count($form->errors()) : 0,
            ], $status)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function redirect(ServerRequestInterface $request, string $path, ?string $message = null): ResponseInterface
    {
        if ($message !== null) {
            Flash::add(Session::fromRequest($request), $message);
        }

        return $this->responses->createResponse(303)
            ->withHeader('Location', $path)
            ->withHeader('Cache-Control', 'no-store');
    }
}
