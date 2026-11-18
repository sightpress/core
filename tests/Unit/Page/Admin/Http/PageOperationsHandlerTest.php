<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Page\Admin\Http\PageOperationsHandler;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Tests\Support\PageAdminFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(PageOperationsHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class PageOperationsHandlerTest extends TestCase
{
    private PageAdminFixture $fixture;

    private User $editor;

    private Page $about;

    private Page $contact;

    protected function setUp(): void
    {
        $this->fixture = new PageAdminFixture();
        $this->editor = $this->fixture->admin->auth->user('bea@example.org', 'editor');
        $this->about = $this->fixture->pages->published('About');
        $this->contact = $this->fixture->pages->published('Contact');
    }

    public function testCopiesThePageAndOpensTheCopy(): void
    {
        $response = $this->send($this->editor, $this->about, ['op' => 'copy']);

        $copy = $this->fixture->pages->repository()->findByPath('en-GB', 'about-copy')?->page();
        self::assertNotNull($copy);
        self::assertSame([303, '/admin/pages/' . $copy->id()], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
        self::assertSame(['Copied the page. This is the copy, a new draft: change its title and address before you publish it.'], $this->flash());
        self::assertSame($this->editor->id(), $copy->authorId());
    }

    public function testOnlyPeopleWhoStartPagesCopyThem(): void
    {
        self::assertSame('/admin/pages/7/operations', PageOperationsHandler::path(7));
        $request = $this->fixture->admin->request($this->editor, 'POST', '/x', ['op' => 'copy'], $this->fixture->session)
            ->withAttribute(\SightPress\Core\Page\Admin\PageAdmin::ID_ATTRIBUTE, (string) $this->about->id())
            ->withAttribute(\SightPress\Core\Rbac\Contract\Actor::class, new \SightPress\Core\Rbac\Contract\Actor((int) $this->editor->id(), ['admin.access', 'page.publish']));

        $this->expectException(HttpForbiddenException::class);
        $this->fixture->operationsHandler()->handle($request);
    }

    public function testACopyThatCantBeMadeIsExplained(): void
    {
        $welsh = new PageAdminFixture(['cy']);
        $page = $welsh->pages->editor()->create('cy', new PageContent('Amdanom'));
        $editor = $welsh->admin->auth->user('bea@example.org', 'editor');
        $nowhere = new \SightPress\Core\Tests\Support\PageFixture($welsh->pages->database, [], ['en-GB']);
        $handler = new PageOperationsHandler($welsh->admin->shell(), $welsh->pageAdmin(), $nowhere->editor(), $welsh->pages->repository(), $welsh->pages->addresses(), $welsh->authorizer(), $welsh->pages->site);

        $response = $welsh->handle($handler, $editor, 'POST', '/x', ['op' => 'copy'], (int) $page->id());

        self::assertSame('/admin/pages/' . $page->id(), $response->getHeaderLine('Location'));
        self::assertSame(['The page isn\'t written in any of the site\'s languages, so there is nothing to copy.'], array_map(strval(...), Flash::take($welsh->session)));
    }

    public function testMovesThePageOneStepOrUnderAnotherPage(): void
    {
        $this->fixture->pages->addresses()->setHome($this->about);
        $this->send($this->editor, $this->contact, ['op' => 'up']);
        self::assertSame([0, 1], [$this->contact->position(), $this->about->position()]);
        self::assertSame(['Moved the page. Its address is now /contact; links to its old address still lead to it.'], $this->flash());

        $this->send($this->editor, $this->contact, ['op' => 'move', 'parent' => (string) $this->about->id()]);
        self::assertSame('about/contact', $this->contact->translation('en-GB')?->path());
        $this->flash();

        $this->send($this->editor, $this->contact, ['op' => 'move', 'parent' => PageOperationsHandler::TOP]);
        self::assertNull($this->contact->parent());
        $this->flash();

        $response = $this->send($this->editor, $this->about, ['op' => 'up']);
        self::assertSame('/admin/pages/' . $this->about->id(), $response->getHeaderLine('Location'));
        self::assertSame(['The page is already the first one at its level.'], $this->flash());
    }

    public function testAPageWithoutTranslationsMovesToo(): void
    {
        $bare = new Page(null, 2, null, new \DateTimeImmutable());
        $repository = $this->fixture->pages->repository();
        $repository->add($bare);
        $repository->flush();
        $bare->placeInTree();
        $repository->flush();

        $this->send($this->editor, $bare, ['op' => 'up']);

        self::assertSame(['Moved the page.'], $this->flash());
    }

    public function testRefusesWhatThePersonMayNotDoAndUnknownButtons(): void
    {
        $contributor = $this->fixture->admin->auth->user('cleo@example.org', 'contributor');
        $member = $this->fixture->admin->auth->user('max@example.org', 'member');
        $draft = $this->fixture->pages->editor()->create('en-GB', new PageContent('Draft'));
        foreach ([
            [$member, $this->about, ['op' => 'copy'], HttpForbiddenException::class],
            [$contributor, $this->about, ['op' => 'up'], HttpForbiddenException::class],
            [$contributor, $draft, ['op' => 'up'], HttpNotFoundException::class],
            [$this->editor, $this->about, ['op' => 'teleport'], HttpBadRequestException::class],
            [$this->editor, $this->about, ['op' => 'move', 'parent' => 'nowhere'], HttpBadRequestException::class],
            [$this->editor, $this->about, ['op' => 'move', 'parent' => '999'], HttpBadRequestException::class],
        ] as [$user, $page, $body, $exception]) {
            try {
                $this->send($user, $page, $body);
                self::fail('Expected ' . $exception);
            } catch (\Exception $thrown) {
                self::assertInstanceOf($exception, $thrown);
            }
        }
        $this->expectException(HttpForbiddenException::class);
        $this->send($this->fixture->admin->auth->user('eve@example.org', null), $this->about, ['op' => 'copy']);
    }

    /**
     * @param array<string, string> $body
     */
    private function send(User $user, Page $page, array $body): ResponseInterface
    {
        return $this->fixture->handle($this->fixture->operationsHandler(), $user, 'POST', '/admin/pages/x/operations', $body, (int) $page->id());
    }

    /**
     * @return list<string>
     */
    private function flash(): array
    {
        return array_map(strval(...), Flash::take($this->fixture->session));
    }
}
