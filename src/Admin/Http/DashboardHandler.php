<?php

declare(strict_types=1);

namespace SightPress\Core\Admin\Http;

use Psr\Log\LoggerInterface;
use SightPress\Core\Admin\AdminShell;
use SightPress\Core\Infrastructure\Dashboard\DashboardWidgets;
use Throwable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Upload\ServerLimitsCheck;
use SightPress\Core\Page\Contract\PageChange;
use SightPress\Core\Page\Contract\PageOverview;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\User\Contract\AccountDirectory;

/**
 * `/admin`: the dashboard. People who manage the settings first see the server settings that stop uploads or
 * saves from working ({@see ServerLimitsCheck}); then the widgets of the site's modules the person may see (for
 * example analytics' "Last 7 days"); then people who write pages see their drafts, people who publish see the pages
 * waiting for review, and everyone sees the latest changes with who made them.
 */
final readonly class DashboardHandler implements RequestHandlerInterface
{
    public const int LIST_LENGTH = 5;
    public const int ACTIVITY_LENGTH = 10;

    public function __construct(
        private AdminScreens $screens,
        private PageOverview $pages,
        private AccountDirectory $accounts,
        private ServerLimitsCheck $check,
        private AdminShell $shell,
        private DashboardWidgets $widgets,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        $changes = $this->pages->recentChanges(self::ACTIVITY_LENGTH);
        $ids = array_values(array_filter(
            array_map(static fn (PageChange $change): ?int => $change->savedBy, $changes),
            is_int(...),
        ));
        $names = [];
        foreach ($this->accounts->findAccounts($ids) as $account) {
            $names[(int) $account->id()] = $account->effectiveName();
        }

        return $this->screens->render($request, '@admin/dashboard.html.twig', [
            'server_problems' => $actor->has('settings.manage') ? $this->check->problems() : [],
            'widgets' => $this->widgets($request, $actor),
            'drafts' => $actor->has('page.create') && $actor->userId !== null
                ? $this->pages->draftsBy($actor->userId, self::LIST_LENGTH)
                : null,
            'reviews' => $actor->has('page.publish') ? $this->pages->awaitingReview(self::LIST_LENGTH) : null,
            'activity' => array_map(static fn (PageChange $change): array => [
                'change' => $change,
                'by' => $change->savedBy === null ? null : ($names[$change->savedBy] ?? null),
            ], $changes),
        ]);
    }

    /**
     * The widgets of the site's modules the person may see, each as its title and HTML. A widget that fails is left
     * out and logged, so one module can't break the dashboard.
     *
     * @return list<array{id: string, title: string, html: string}>
     */
    private function widgets(ServerRequestInterface $request, Actor $actor): array
    {
        $context = new AdminDashboardContext($this->shell->viewer($request), $actor, $this->widgets);
        $shown = [];
        foreach ($this->widgets->all() as $widget) {
            $permission = $widget->permission();
            if ($permission !== null && !$actor->has($permission)) {
                continue;
            }
            try {
                $shown[] = ['id' => $widget->id(), 'title' => $widget->title(), 'html' => $widget->render($context)];
            } catch (Throwable $exception) {
                $this->logger->error('Dashboard widget {widget} failed: {class}: {message}', ['widget' => $widget->id(), 'class' => $exception::class, 'message' => $exception->getMessage()]);
            }
        }

        return $shown;
    }
}
