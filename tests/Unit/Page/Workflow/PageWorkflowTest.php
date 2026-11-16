<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Workflow;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Contract\PageTransitioned;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\Workflow\PageMarkingStore;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\Page\Workflow\WorkflowException;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\PageVoter;
use SightPress\Core\Rbac\VoterAuthorizer;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(PageWorkflow::class)]
#[CoversClass(PageMarkingStore::class)]
#[CoversClass(WorkflowException::class)]
#[CoversClass(PageTransition::class)]
#[CoversClass(PageTransitioned::class)]
#[UsesNamespace('SightPress\Core')]
final class PageWorkflowTest extends TestCase
{
    private const array CONTRIBUTOR = ['admin.access', 'page.create', 'page.edit.own', 'page.submit'];

    private const array EDITOR = ['admin.access', 'page.create', 'page.edit.own', 'page.edit.any', 'page.submit', 'page.publish'];

    private PageFixture $fixture;

    private PageWorkflow $workflow;

    protected function setUp(): void
    {
        $this->fixture = new PageFixture();
        $this->workflow = new PageWorkflow($this->fixture->repository(), new VoterAuthorizer([new PageVoter()]), $this->fixture->clock, $this->fixture);
    }

    public function testAContributorSubmitsAndAnEditorApprovesPublishesAndUnpublishes(): void
    {
        $contributor = new Actor(7, self::CONTRIBUTOR);
        $editor = new Actor(3, self::EDITOR);
        $page = $this->page(7);

        self::assertSame([PageTransition::Submit], $this->workflow->available($page, $contributor));
        $this->workflow->apply($page, PageTransition::Submit, $contributor);
        self::assertSame(PageState::InReview, $page->state());
        self::assertSame([], $this->workflow->available($page, $contributor), 'a contributor waits for the review');
        self::assertSame([PageTransition::Reject, PageTransition::Approve], $this->workflow->available($page, $editor));

        $this->workflow->apply($page, PageTransition::Approve, $editor, '  ');
        $this->workflow->apply($page, PageTransition::Publish, $editor);
        self::assertSame([PageTransition::Unpublish], $this->workflow->available($page, $editor));
        $this->workflow->apply($page, PageTransition::Unpublish, $editor, 'Out of date');

        $this->fixture->database->entityManager->clear();
        self::assertSame(PageState::Draft, $this->fixture->repository()->find((int) $page->id())?->state());
        $id = (int) $page->id();
        self::assertEquals(
            [
                new PageTransitioned($id, PageTransition::Submit, PageState::Draft, PageState::InReview, 7),
                new PageTransitioned($id, PageTransition::Approve, PageState::InReview, PageState::Approved, 3),
                new PageTransitioned($id, PageTransition::Publish, PageState::Approved, PageState::Published, 3),
                new PageTransitioned($id, PageTransition::Unpublish, PageState::Published, PageState::Draft, 3, 'Out of date'),
            ],
            array_values(array_filter($this->fixture->events, static fn (object $event): bool => $event instanceof PageTransitioned)),
        );
    }

    public function testAnEditorSendsAPageBackWithAComment(): void
    {
        $page = $this->page(7);
        $this->workflow->apply($page, PageTransition::Submit, new Actor(7, self::CONTRIBUTOR));

        $this->workflow->apply($page, PageTransition::Reject, new Actor(3, self::EDITOR), ' Add the opening times. ');

        self::assertSame(PageState::Draft, $page->state());
        $last = end($this->fixture->events);
        self::assertInstanceOf(PageTransitioned::class, $last);
        self::assertSame('Add the opening times.', $last->comment);
    }

    public function testRefusesStepsThatDontStartFromThePagesState(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('"Publish" can\'t be done now: the page is a draft.');

        $this->workflow->apply($this->page(7), PageTransition::Publish, new Actor(3, self::EDITOR));
    }

    public function testRefusesPeopleWhoMayNotTakeTheStep(): void
    {
        $page = $this->page(7);
        $this->workflow->apply($page, PageTransition::Submit, new Actor(7, self::CONTRIBUTOR));

        try {
            $this->workflow->apply($page, PageTransition::Approve, new Actor(7, self::CONTRIBUTOR));
            self::fail('Expected the step to be refused.');
        } catch (WorkflowException $exception) {
            self::assertTrue($exception->forbidden);
            self::assertSame('You don\'t have permission to do "Approve" on this page.', $exception->getMessage());
        }
        self::assertFalse($this->workflow->can($this->page(8), PageTransition::Submit, new Actor(7, self::CONTRIBUTOR)), 'not their page');
        self::assertFalse($this->workflow->can($page, PageTransition::Approve, Actor::anonymous()));
        self::assertSame(PageState::InReview, $page->state());
    }

    public function testLimitsTheComment(): void
    {
        $page = $this->page(7);

        try {
            $this->workflow->apply($page, PageTransition::Submit, new Actor(7, self::CONTRIBUTOR), str_repeat('a', 1001));
            self::fail('Expected the comment to be refused.');
        } catch (WorkflowException $exception) {
            self::assertFalse($exception->forbidden);
            self::assertSame('Keep the comment to 1000 characters or fewer.', $exception->getMessage());
        }
        self::assertSame(PageState::Draft, $page->state());
    }

    /**
     * @return iterable<string, array{PageState, list<string>}>
     */
    public static function states(): iterable
    {
        yield 'in review' => [PageState::InReview, ['waiting for review']];
        yield 'approved' => [PageState::Approved, ['approved']];
        yield 'published' => [PageState::Published, ['published']];
        yield 'archived' => [PageState::Archived, ['archived']];
        yield 'trash' => [PageState::Trash, ['in the trash']];
    }

    /**
     * @param list<string> $words
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('states')]
    public function testNamesThePagesStateWhenAStepCantBeTaken(PageState $state, array $words): void
    {
        $page = $this->page(7);
        $page->changeState($state, $this->fixture->clock->now());

        $this->expectExceptionMessage('"Submit for review" can\'t be done now: the page is ' . $words[0] . '.');

        $this->workflow->apply($page, PageTransition::Submit, new Actor(3, self::EDITOR));
    }

    public function testDescribesEachStep(): void
    {
        self::assertSame(
            [
                ['submit', 'draft', 'in_review', 'page.submit', 'Submit for review'],
                ['reject', 'in_review', 'draft', 'page.reject', 'Send back to draft'],
                ['approve', 'in_review', 'approved', 'page.approve', 'Approve'],
                ['publish', 'approved', 'published', 'page.publish', 'Publish'],
                ['unpublish', 'published', 'draft', 'page.unpublish', 'Unpublish'],
            ],
            array_map(static fn (PageTransition $t): array => [$t->value, $t->fromState()->value, $t->toState()->value, $t->action()->value, $t->label()], PageTransition::cases()),
        );
    }

    public function testTheMarkingStoreOnlyHandlesPagesChangedAtAGivenTime(): void
    {
        $store = new PageMarkingStore();
        $page = $this->page(7);

        self::assertSame(['draft' => 1], $store->getMarking($page)->getPlaces());
        foreach ([
            static fn () => $store->getMarking(new \stdClass()),
            static fn () => $store->setMarking($page, new \Symfony\Component\Workflow\Marking(['approved' => 1])),
            fn () => $store->setMarking($page, new \Symfony\Component\Workflow\Marking(['approved' => 1, 'draft' => 1]), ['now' => $this->fixture->clock->now()]),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a LogicException.');
            } catch (\LogicException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        self::assertSame(PageState::Draft, $page->state());
    }

    private function page(int $author): Page
    {
        return $this->fixture->editor()->create('en-GB', new PageContent('Page ' . bin2hex(random_bytes(3))), null, $author);
    }
}
