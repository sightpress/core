<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Schedule;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Page\Contract\PageScheduled;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Contract\ScheduledStepFailed;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\Page\Workflow\PublishBlocked;
use SightPress\Core\Page\Workflow\WorkflowException;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\Contract\PermissionLookup;
use SightPress\Core\Tenant\Site;

/**
 * Publishing and taking pages off the site at a set time (P-3, ADR-0029). People who may publish set a time to
 * publish the page (it happens once the page is approved: at that time, or as soon as it is approved after it) and a
 * time to take it off the site again (while it is published). Times are stored in UTC.
 *
 * {@see run()} is what `schedule:run` does for the site: every step goes through the workflow, with the permissions
 * the person who set the schedule has at that moment, so the publish checks and the voter apply as if they had
 * pressed the button. A step that can't be taken is reported and that part of the schedule cleared, so it isn't
 * tried again every minute. Once a step is taken, the page's state change clears it ({@see Page::changeState()}), so
 * a second run never takes it twice; a run that was missed catches up on the next one.
 */
final readonly class PageSchedule
{
    public const string PERMISSION = 'page.publish';

    public function __construct(
        private PageRepository $pages,
        private PageWorkflow $workflow,
        private PermissionLookup $permissions,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
        private Site $site,
    ) {
    }

    /**
     * Sets when the page is published and taken off the site; null for both clears the schedule.
     *
     * @throws PageException when the person may not publish, the page can't be scheduled now or a time is wrong
     */
    public function set(Page $page, ?DateTimeImmutable $publishAt, ?DateTimeImmutable $unpublishAt, Actor $actor): void
    {
        if (!$actor->has(self::PERMISSION)) {
            throw new PageException('Only people who may publish pages can schedule them.');
        }
        $now = $this->clock->now();
        if ($publishAt !== null || $unpublishAt !== null) {
            if (in_array($page->state(), [PageState::Archived, PageState::Trash], true)) {
                throw new PageException('Archived pages and pages in the trash can’t be scheduled. Restore the page first.');
            }
            if ($publishAt !== null && $page->state() === PageState::Published) {
                throw new PageException('The page is published already, so it can only get a time to be taken off the site.');
            }
            if ($publishAt !== null && $publishAt <= $now) {
                throw new PageException('Choose a time to publish that is still to come.');
            }
            if ($unpublishAt !== null && $unpublishAt <= $now) {
                throw new PageException('Choose a time to take the page off the site that is still to come.');
            }
            if ($publishAt !== null && $unpublishAt !== null && $unpublishAt <= $publishAt) {
                throw new PageException('The page must be taken off the site after it is published.');
            }
        }
        $page->schedule($publishAt, $unpublishAt, $actor->userId, $now);
        $this->pages->flush();
        $this->events->dispatch(new PageScheduled((int) $page->id(), $publishAt, $unpublishAt, $actor->userId));
    }

    /**
     * Takes every step whose time has come.
     *
     * @return list<string> what was done, a sentence each, for the operator
     */
    public function run(DateTimeImmutable $now): array
    {
        $lines = [];
        foreach ($this->pages->duePublications($now) as $page) {
            $lines[] = $this->step($page, PageTransition::Publish);
        }
        foreach ($this->pages->dueUnpublications($now) as $page) {
            $lines[] = $this->step($page, PageTransition::Unpublish);
        }

        return $lines;
    }

    private function step(Page $page, PageTransition $transition): string
    {
        $by = $page->scheduledBy();
        $actor = new Actor($by, $by === null ? [] : $this->permissions->permissionNamesOf($by));
        $title = $this->title($page);
        try {
            $this->workflow->apply($page, $transition, $actor, $transition === PageTransition::Publish ? 'Published on schedule.' : 'Taken off the site on schedule.');

            return sprintf($transition === PageTransition::Publish ? 'Published “%s” on schedule.' : 'Took “%s” off the site on schedule.', $title);
        } catch (PublishBlocked) {
            $reason = 'the publish checks found errors';
        } catch (WorkflowException $exception) {
            $reason = rtrim($exception->getMessage(), '.');
        }
        if ($transition === PageTransition::Publish) {
            $page->schedule(null, $page->unpublishAt(), $by, $this->clock->now());
        } else {
            $page->schedule($page->publishAt(), null, $by, $this->clock->now());
        }
        $this->pages->flush();
        $this->events->dispatch(new ScheduledStepFailed((int) $page->id(), $transition, $reason, $by));

        return sprintf($transition === PageTransition::Publish ? 'Couldn’t publish “%s” on schedule: %s. Its publishing time was cleared.' : 'Couldn’t take “%s” off the site on schedule: %s. Its end time was cleared.', $title, $reason);
    }

    private function title(Page $page): string
    {
        $translations = $page->translations();
        $translation = $translations[$this->site->defaultLocale] ?? (array_values($translations)[0] ?? null);

        return $translation?->title() ?? sprintf('page %d', (int) $page->id());
    }
}
