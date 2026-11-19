<?php

declare(strict_types=1);

namespace SightPress\Core\Admin\Http;

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
 * `/admin`: the dashboard. People who write pages see their drafts, people who publish see the pages waiting for
 * review, and everyone sees the latest changes with who made them. People who manage the settings first see the
 * server settings that stop uploads or saves from working ({@see ServerLimitsCheck}).
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
}
