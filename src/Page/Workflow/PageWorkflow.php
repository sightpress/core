<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Workflow;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Contract\PageTransitioned;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Page\Publish\PublishChecker;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageState;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

/**
 * Moves pages through the workflow (ADR-0006): the state machine decides which steps exist from each state, the
 * authorizer (page voter) decides who may take them, and publishing needs the publish checks to find no errors
 * ({@see PublishChecker}). After a step is stored, {@see PageTransitioned} is dispatched.
 *
 * Domain code and the admin use this service only, never the workflow library.
 */
final readonly class PageWorkflow
{
    public const int COMMENT_MAX_LENGTH = 1000;

    private StateMachine $machine;

    public function __construct(
        private PageRepository $pages,
        private Authorizer $authorizer,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
        private PublishChecker $checks,
    ) {
        $transitions = array_map(
            static fn (PageTransition $transition): Transition => new Transition($transition->value, $transition->fromState()->value, $transition->toState()->value),
            PageTransition::cases(),
        );
        $places = array_map(static fn (PageState $state): string => $state->value, PageState::cases());
        $this->machine = new StateMachine(new Definition($places, $transitions, PageState::Draft->value), new PageMarkingStore(), name: 'page');
    }

    /**
     * The steps this person may take on the page now, in the order of {@see PageTransition::cases()}.
     *
     * @return list<PageTransition>
     */
    public function available(Page $page, Actor $actor): array
    {
        return array_values(array_filter(
            PageTransition::cases(),
            fn (PageTransition $transition): bool => $this->can($page, $transition, $actor),
        ));
    }

    public function can(Page $page, PageTransition $transition, Actor $actor): bool
    {
        return $this->machine->can($page, $transition->value)
            && $this->authorizer->can($actor, $transition->action()->value, $page);
    }

    /**
     * @throws WorkflowException when the step doesn't start from the page's state, or the person may not take it
     * @throws PublishBlocked    when publishing and the publish checks found errors
     */
    public function apply(Page $page, PageTransition $transition, Actor $actor, ?string $comment = null): void
    {
        $from = $page->state();
        if (!$this->machine->can($page, $transition->value)) {
            throw new WorkflowException(sprintf(
                '"%s" can\'t be done now: the page is %s.',
                $transition->label(),
                self::describe($from),
            ));
        }
        if (!$this->authorizer->can($actor, $transition->action()->value, $page)) {
            throw new WorkflowException(sprintf('You don\'t have permission to do "%s" on this page.', $transition->label()), true);
        }
        if ($transition === PageTransition::Publish) {
            $report = $this->checks->check($page);
            if (!$report->canPublish()) {
                throw new PublishBlocked($report);
            }
        }
        $comment = $comment === null ? null : trim($comment);
        if ($comment !== null && mb_strlen($comment) > self::COMMENT_MAX_LENGTH) {
            throw new WorkflowException(sprintf('Keep the comment to %d characters or fewer.', self::COMMENT_MAX_LENGTH));
        }

        $this->machine->apply($page, $transition->value, ['now' => $this->clock->now()]);
        $this->pages->flush();

        $this->events->dispatch(new PageTransitioned(
            (int) $page->id(),
            $transition,
            $from,
            $page->state(),
            $actor->userId,
            $comment === '' ? null : $comment,
        ));
    }

    private static function describe(PageState $state): string
    {
        return match ($state) {
            PageState::Draft => 'a draft',
            PageState::InReview => 'waiting for review',
            PageState::Approved => 'approved',
            PageState::Published => 'published',
            PageState::Archived => 'archived',
            PageState::Trash => 'in the trash',
        };
    }
}
