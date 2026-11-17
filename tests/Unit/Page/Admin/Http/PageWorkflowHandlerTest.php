<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Page\Admin\Http\PageWorkflowHandler;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tests\Support\PageAdminFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;

#[CoversClass(PageWorkflowHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class PageWorkflowHandlerTest extends TestCase
{
    private PageAdminFixture $fixture;

    private User $editor;

    private User $contributor;

    private Page $page;

    protected function setUp(): void
    {
        $this->fixture = new PageAdminFixture();
        $this->editor = $this->fixture->admin->auth->user('bea@example.org', 'editor');
        $this->contributor = $this->fixture->admin->auth->user('cleo@example.org', 'contributor');
        $this->page = $this->fixture->pages->editor()->create('en-GB', new PageContent('Opening hours', summary: 'When.', description: 'Times.', blocks: [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Every day', 'level' => 2]],
        ]), null, (int) $this->contributor->id());
    }

    public function testTakesThePageThroughReviewAndPublishing(): void
    {
        $steps = [
            [$this->contributor, 'submit', PageState::InReview, 'Sent for review. The editors have been told by email.'],
            [$this->editor, 'reject', PageState::Draft, 'Sent back to draft. The writer can change it and send it again.'],
            [$this->contributor, 'submit', PageState::InReview, 'Sent for review. The editors have been told by email.'],
            [$this->editor, 'approve', PageState::Approved, 'Approved. It can be published now.'],
            [$this->editor, 'publish', PageState::Published, 'Published. Visitors can see the page now.'],
            [$this->editor, 'unpublish', PageState::Draft, 'Unpublished. Visitors can’t see the page any more; it is a draft again.'],
        ];
        foreach ($steps as [$user, $transition, $state, $message]) {
            $response = $this->send($user, ['transition' => $transition, 'comment' => 'Note']);
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('/admin/pages/' . $this->page->id(), $response->getHeaderLine('Location'));
            self::assertSame($state, $this->page->state(), $transition);
            self::assertSame([$message], Flash::take($this->fixture->session));
        }
    }

    public function testAStepThatCantBeTakenNowIsExplained(): void
    {
        $this->send($this->editor, ['transition' => 'publish']);

        self::assertSame(['"Publish" can\'t be done now: the page is a draft.'], Flash::take($this->fixture->session));
    }

    public function testRefusedPublishingShowsTheProblemsWithFocusOnThem(): void
    {
        $this->page->changeState(PageState::Approved, new \DateTimeImmutable());
        $media = new \SightPress\Core\Media\Entity\Media(str_repeat('a', 32), str_repeat('b', 64), new \SightPress\Core\Media\MediaFormat('image/png', \SightPress\Core\Media\Contract\MediaKind::Image, 'png', 'PNG'), 'f.png', 'photo.png', 1, 10, 10, [], null, new \DateTimeImmutable());
        $this->fixture->media()->save($media);
        $this->fixture->pages->editor()->save($this->page, 'en-GB', new PageContent('Opening hours', blocks: [
            ['type' => 'image', 'version' => 1, 'data' => ['media' => (int) $media->id(), 'alt' => 'A photo']],
        ]));

        $response = $this->send($this->editor, ['transition' => 'publish']);

        self::assertSame(422, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertMatchesRegularExpression('/<section class="publish-report publish-report--blocked" id="publish-report"[^>]* autofocus>/', $html);
        self::assertStringContainsString('<legend>Block 1 of 1: Image</legend>', $html);
        self::assertSame(PageState::Approved, $this->page->state());
    }

    public function testRefusesPeopleWhoMayNotTakeTheStep(): void
    {
        $this->page->changeState(PageState::InReview, new \DateTimeImmutable());

        $this->expectException(HttpForbiddenException::class);
        $this->send($this->contributor, ['transition' => 'approve']);
    }

    public function testRefusesMembersAndUnknownSteps(): void
    {
        try {
            $this->send($this->fixture->admin->auth->user('max@example.org', 'member'), ['transition' => 'submit']);
            self::fail('Members may not use it.');
        } catch (HttpForbiddenException) {
        }

        $this->expectException(HttpBadRequestException::class);
        $this->send($this->editor, ['transition' => 'teleport']);
    }

    /**
     * @param array<string, string> $body
     */
    private function send(User $user, array $body): \Psr\Http\Message\ResponseInterface
    {
        return $this->fixture->handle($this->fixture->workflowHandler(), $user, 'POST', '/admin/pages/x/workflow', $body, (int) $this->page->id());
    }
}
