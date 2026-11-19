<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Admin;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Analytics\AnalyticsModule;
use SightPress\Core\Analytics\AnalyticsStore;
use SightPress\Core\Analytics\Contract\AnalyticsDataDeleted;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Message;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/analytics/delete`: asks whether to delete all of the site's analytics for good, and deletes them when
 * confirmed (audited); counting goes on from then. Needs `analytics.manage`.
 */
final readonly class DeleteAnalyticsHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/analytics/delete';

    public function __construct(
        private AdminScreens $screens,
        private AnalyticsStore $store,
        private EventDispatcherInterface $events,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(AnalyticsModule::MANAGE)) {
            throw new HttpForbiddenException($request);
        }
        if ($request->getMethod() === 'POST') {
            $this->store->deleteAll();
            $this->events->dispatch(new AnalyticsDataDeleted($actor->userId));

            return $this->screens->redirect($request, AnalyticsHandler::PATH, new Message('Deleted all of the site’s analytics. Counting goes on from now.'));
        }

        return $this->screens->render($request, '@analytics/delete.html.twig', [
            'action' => self::PATH,
            'cancel' => AnalyticsHandler::PATH . '#analytics-manage',
            'first_day' => $this->store->firstDay(),
        ]);
    }
}
