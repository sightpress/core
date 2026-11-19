<?php

declare(strict_types=1);

namespace SightPress\Core\Feature\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteModuleException;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Message;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * `/admin/features/delete-data?module=…`: asks whether to delete an off feature's data for good, and deletes it when
 * confirmed; it can't be undone. Needs `module.manage`, which only admins have unless an admin gives it to another role.
 */
final readonly class DeleteFeatureDataHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/features/delete-data';

    public function __construct(
        private AdminScreens $screens,
        private SiteModules $features,
        private Site $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(FeaturesHandler::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $body = $request->getParsedBody();
        $values = $request->getMethod() === 'POST' && is_array($body) ? $body : $request->getQueryParams();
        $id = is_string($values['module'] ?? null) ? $values['module'] : '';
        try {
            $feature = $this->features->get($this->site, $id);
        } catch (SiteModuleException) {
            throw new HttpNotFoundException($request, 'There is no such feature.');
        }

        if ($request->getMethod() === 'POST') {
            try {
                $undone = $this->features->deleteData($this->site, $id, $actor->userId);
            } catch (SiteModuleException $exception) {
                return $this->screens->redirect($request, FeaturesHandler::at($id), $exception->text());
            }

            return $this->screens->redirect($request, FeaturesHandler::at($id), $undone > 0
                ? new Message('Deleted the data of {name}. Turning it on again starts afresh.', ['name' => $feature->name])
                : new Message('{name} has no data to delete.', ['name' => $feature->name]));
        }

        return $this->screens->render($request, '@feature/delete-data.html.twig', [
            'feature' => $feature,
            'action' => self::PATH,
            'cancel' => FeaturesHandler::at($id),
        ]);
    }
}
