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
use SightPress\Core\Tenant\SiteModuleState;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Message;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/features`: the site's optional features, each under its own heading with what it does, whether it is on
 * (in words), what turning it off does and one button: "Turn on …" (a POST form here) or "Turn off …" (which asks
 * first, {@see TurnOffFeatureHandler}). A feature the organisation set has no button. Needs `module.manage`.
 *
 * After a change the screen comes back at the feature's heading (`#feature-<id>`), with the result in the live
 * region.
 */
final readonly class FeaturesHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/features';
    public const string PERMISSION = 'module.manage';

    public function __construct(
        private AdminScreens $screens,
        private SiteModules $features,
        private Site $site,
    ) {
    }

    /**
     * The screen's address at a feature's heading.
     */
    public static function at(string $id): string
    {
        return self::PATH . '#' . self::anchor($id);
    }

    /**
     * Id of a feature's heading: `feature-` and the module id, with dots and other signs as hyphens.
     */
    public static function anchor(string $id): string
    {
        return 'feature-' . (string) preg_replace('/[^a-z0-9]+/', '-', $id);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(self::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }

        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $id = is_array($body) && is_string($body['on'] ?? null) ? $body['on'] : '';
            try {
                $feature = $this->features->get($this->site, $id);
                $changed = $this->features->turnOn($this->site, $id, $actor->userId);
            } catch (SiteModuleException $exception) {
                return $this->screens->redirect($request, $id === '' ? self::PATH : self::at($id), $exception->text());
            }

            return $this->screens->redirect($request, self::at($id), $changed
                ? new Message('{name} is on.', ['name' => $feature->name])
                : new Message('{name} was already on.', ['name' => $feature->name]));
        }

        return $this->screens->render($request, '@feature/features.html.twig', [
            'features' => array_map(fn (SiteModuleState $feature): array => [
                'feature' => $feature,
                'anchor' => self::anchor($feature->id),
                'has_data' => !$feature->on && !$feature->locked && $this->features->hasData($this->site, $feature->id),
            ], $this->features->all($this->site)),
            'action' => self::PATH,
            'turn_off' => TurnOffFeatureHandler::PATH,
            'delete_data' => DeleteFeatureDataHandler::PATH,
        ]);
    }
}
