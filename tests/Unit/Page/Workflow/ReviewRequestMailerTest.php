<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Workflow;

use SightPress\Core\Tests\Support\Text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Contract\PageTransitioned;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\Workflow\ReviewRequestMailer;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Mime\Address;

#[CoversClass(ReviewRequestMailer::class)]
#[UsesNamespace('SightPress\Core')]
final class ReviewRequestMailerTest extends TestCase
{
    private AuthFixture $auth;

    private PageFixture $pages;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->pages = new PageFixture($this->auth->database);
    }

    public function testEmailsEveryoneWhoMayPublishExceptTheAuthor(): void
    {
        $editor = $this->auth->user('ed@example.org', 'editor');
        $admin = $this->auth->user('al@example.org', 'admin');
        $this->auth->user('gone@example.org', 'editor', deactivated: true);
        $this->auth->user('new@example.org', 'editor', pending: true);
        $author = $this->auth->user('cy@example.org', 'contributor');
        $editorWhoSubmits = $this->auth->user('self@example.org', 'editor');
        $page = $this->pages->editor()->create('en-GB', new PageContent('Opening times'));
        $id = (int) $page->id();

        $this->mailer()(new PageTransitioned($id, PageTransition::Submit, PageState::Draft, PageState::InReview, (int) $author->id()));

        self::assertSame(['ed@example.org', 'al@example.org', 'self@example.org'], Text::of($this->recipients()));
        $email = $this->auth->sent[0];
        self::assertEquals([new Address('ed@example.org', 'Ada')], Text::of($email->getTo()));
        self::assertSame('Please review "Opening times" on Demo', Text::of($email->getSubject()));
        self::assertStringContainsString('Ada submitted the page "Opening times" on Demo for review.', (string) $email->getTextBody());
        self::assertStringContainsString("https://demo.example/admin/pages/{$id}\n", (string) $email->getTextBody());
        self::assertStringContainsString('<a href="https://demo.example/admin/pages/' . $id . '"', (string) $email->getHtmlBody());

        $this->auth->sent = [];
        $this->mailer()(new PageTransitioned($id, PageTransition::Submit, PageState::Draft, PageState::InReview, (int) $editorWhoSubmits->id()));
        self::assertSame(['ed@example.org', 'al@example.org'], Text::of($this->recipients()));
        self::assertNotNull($editor->id());
        self::assertNotNull($admin->id());
    }

    public function testUsesAnyTitleAndSaysSomeoneWhenTheAuthorIsGone(): void
    {
        $this->auth->user('ed@example.org', 'editor');
        $site = PageFixture::site(['en-GB', 'pt-PT']);
        $page = $this->pages->editor()->create('pt-PT', new PageContent('Horário'));
        $mailer = new ReviewRequestMailer($this->pages->repository(), new RoleRepository($this->auth->database->entityManager), new UserRepository($this->auth->database->entityManager), $this->auth, $this->auth->templates, $this->auth->http, $site, new UserRepository($this->auth->database->entityManager), $this->auth->translations);

        $mailer(new PageTransitioned((int) $page->id(), PageTransition::Submit, PageState::Draft, PageState::InReview, null));

        self::assertSame('Please review "Horário" on Demo', Text::of($this->auth->sent[0]->getSubject()));
        self::assertStringContainsString('Someone submitted the page', (string) $this->auth->sent[0]->getTextBody());
    }

    public function testOnlyReactsToSubmittingAnExistingPage(): void
    {
        $this->auth->user('ed@example.org', 'editor');
        $page = $this->pages->editor()->create('en-GB', new PageContent('Opening times'));

        $this->mailer()(new PageTransitioned((int) $page->id(), PageTransition::Approve, PageState::InReview, PageState::Approved, null));
        $this->mailer()(new PageTransitioned(99999, PageTransition::Submit, PageState::Draft, PageState::InReview, null));

        self::assertSame([], Text::of($this->auth->sent));
    }

    private function mailer(): ReviewRequestMailer
    {
        $entityManager = $this->auth->database->entityManager;

        return new ReviewRequestMailer($this->pages->repository(), new RoleRepository($entityManager), new UserRepository($entityManager), $this->auth, $this->auth->templates, $this->auth->http, $this->pages->site, new UserRepository($entityManager), $this->auth->translations);
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        return array_map(static fn ($email): string => $email->getTo()[0]->getAddress(), $this->auth->sent);
    }
}
