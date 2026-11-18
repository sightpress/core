<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\Schedule\LocalTime;
use SightPress\Core\Page\Schedule\PageSchedule;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * `POST /admin/pages/{id}/schedule`: sets when the page is published and taken off the site (`publish_at`,
 * `unpublish_at`, in the person's own time zone), or clears both (`op=clear`). Needs `page.publish`. Back in the
 * editor, the status region says what was scheduled, in the person's time zone, or what was wrong (the form then
 * shows what was typed).
 */
final readonly class PageScheduleHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdminScreens $screens,
        private PageAdmin $admin,
        private PageSchedule $schedule,
        private Authorizer $authorizer,
        private Site $site,
    ) {
    }

    public static function path(int $id): string
    {
        return PageAdmin::editPath($id) . '/schedule';
    }

    /**
     * The time zone the person reads and types times in.
     */
    public static function timezone(ServerRequestInterface $request, Site $site): string
    {
        return Viewer::fromRequest($request, new Viewer($site->defaultLocale, $site->defaultTimezone))->timezone;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        $page = $this->admin->page($request);
        if (!$this->authorizer->can($actor, PageAction::View->value, $page)) {
            throw new HttpNotFoundException($request, 'There is no page with that number.');
        }
        if (!$actor->has(PageSchedule::PERMISSION)) {
            throw new HttpForbiddenException($request, 'Only people who may publish pages can schedule them.');
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $timezone = self::timezone($request, $this->site);
        $edit = PageAdmin::editPath((int) $page->id());
        try {
            if (($body['op'] ?? null) === 'clear') {
                $publishAt = null;
                $unpublishAt = null;
            } else {
                $publishAt = LocalTime::parse(is_string($body['publish_at'] ?? null) ? $body['publish_at'] : '', $timezone, new Message('Publish at'));
                $unpublishAt = LocalTime::parse(is_string($body['unpublish_at'] ?? null) ? $body['unpublish_at'] : '', $timezone, new Message('Take off the site at'));
            }
            $this->schedule->set($page, $publishAt, $unpublishAt, $actor);
        } catch (PageException $exception) {
            // Back to the editor with what was typed, so it can be corrected rather than typed again.
            $typed = array_filter([
                'publish_at' => is_string($body['publish_at'] ?? null) ? mb_substr($body['publish_at'], 0, 32) : '',
                'unpublish_at' => is_string($body['unpublish_at'] ?? null) ? mb_substr($body['unpublish_at'], 0, 32) : '',
            ], static fn (string $value): bool => $value !== '');

            return $this->screens->redirect($request, $edit . ($typed === [] ? '' : '?' . http_build_query($typed)), $exception->text());
        }

        $show = static fn (\DateTimeImmutable $time): string => $time->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d H:i');

        return $this->screens->redirect($request, $edit, match (true) {
            $publishAt !== null && $unpublishAt !== null => new Message('Scheduled: the page is published at {publish} and taken off the site at {unpublish} ({timezone} time).', ['publish' => $show($publishAt), 'unpublish' => $show($unpublishAt), 'timezone' => $timezone]),
            $publishAt !== null => new Message('Scheduled: the page is published at {publish} ({timezone} time).', ['publish' => $show($publishAt), 'timezone' => $timezone]),
            $unpublishAt !== null => new Message('Scheduled: the page is taken off the site at {unpublish} ({timezone} time).', ['unpublish' => $show($unpublishAt), 'timezone' => $timezone]),
            default => new Message('The schedule is cleared: nothing happens to the page on its own.'),
        });
    }
}
