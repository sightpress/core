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
 * `/admin/features/turn-off?module=…`: asks whether to turn a feature off, saying what that does and that its data is
 * kept, and turns it off when confirmed (`confirm` sent with the form). The "Turn off" button of the *Features*
 * screen posts here without `confirm`, which shows the question. Needs `module.manage`.
 */
final readonly class TurnOffFeatureHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/features/turn-off';

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
        if (!$feature->on || $feature->locked) {
            return $this->screens->redirect($request, FeaturesHandler::at($id), $feature->locked
                ? new Message('{name} is set by your organisation, so it can’t be changed for this site.', ['name' => $feature->name])
                : new Message('{name} was already off.', ['name' => $feature->name]));
        }
        if ($feature->neededBy !== []) {
            return $this->screens->redirect($request, FeaturesHandler::at($id), new Message(
                '{needer} needs {name}, so {name} can’t be turned off while {needer} is on. Turn off {needer} first.',
                ['name' => $feature->name, 'needer' => implode(', ', $feature->neededBy)],
            ));
        }

        if ($request->getMethod() === 'POST' && ($values['confirm'] ?? null) !== null) {
            $this->features->turnOff($this->site, $id, $actor->userId);

            return $this->screens->redirect($request, FeaturesHandler::at($id), new Message(
                '{name} is off. Its data is kept: turn it on again to bring it back.',
                ['name' => $feature->name],
            ));
        }

        return $this->screens->render($request, '@feature/turn-off.html.twig', [
            'feature' => $feature,
            'action' => self::PATH,
            'cancel' => FeaturesHandler::at($id),
        ]);
    }
}
