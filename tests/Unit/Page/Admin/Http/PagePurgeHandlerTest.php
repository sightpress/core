<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Page\Admin\Http\PagePurgeHandler;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tests\Support\PageAdminFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(PagePurgeHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class PagePurgeHandlerTest extends TestCase
{
    private PageAdminFixture $fixture;

    private User $editor;

    private Page $news;

    protected function setUp(): void
    {
        $this->fixture = new PageAdminFixture();
        $this->editor = $this->fixture->admin->auth->user('bea@example.org', 'editor');
        $this->news = $this->fixture->pages->published('News');
    }

    public function testAsksFirstThenDeletesThePageForGood(): void
    {
        $old = $this->fixture->pages->published('Old', [], $this->news);
        $older = $this->fixture->pages->published('Older', [], $this->news);
        foreach ([$this->news, $old, $older] as $page) {
            $page->changeState(PageState::Trash, new \DateTimeImmutable());
        }
        $this->fixture->pages->repository()->flush();

        $html = (string) $this->send($this->editor, $this->news, 'GET')->getBody();
        self::assertStringContainsString('<h1>Delete “News” for good?</h1>', $html);
        self::assertStringContainsString('The 2 pages under it, which are in the trash too, are deleted with it.', $html);
        self::assertStringContainsString('<a href="/admin/pages/' . $this->news->id() . '">Keep it in the trash</a>', $html);

        $response = $this->send($this->editor, $this->news, 'POST');

        self::assertSame([303, PagePurgeHandler::TRASH], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
        self::assertSame(['Deleted “News” for good. Its address now tells visitors the page is gone.'], array_map(strval(...), Flash::take($this->fixture->session)));
        self::assertTrue($this->fixture->pages->redirects()->find('en-GB', 'news')?->isGone());
    }

    public function testOnePageUnderItAndAPageWithoutTranslations(): void
    {
        $this->fixture->pages->published('Old', [], $this->news)->changeState(PageState::Trash, new \DateTimeImmutable());
        $this->news->changeState(PageState::Trash, new \DateTimeImmutable());
        self::assertStringContainsString('The page under it, which is in the trash too, is deleted with it.', (string) $this->send($this->editor, $this->news, 'GET')->getBody());

        $bare = new Page(null, 1, null, new \DateTimeImmutable());
        $bare->changeState(PageState::Trash, new \DateTimeImmutable());
        $repository = $this->fixture->pages->repository();
        $repository->add($bare);
        $repository->flush();
        $bare->placeInTree();
        $repository->flush();
        $html = (string) $this->send($this->editor, $bare, 'GET')->getBody();
        self::assertStringContainsString('<h1>Delete “Page ' . $bare->id() . '” for good?</h1>', $html);
        self::assertStringNotContainsString('under it', $html);
    }

    public function testAPageUnderItThatIsntInTheTrashStopsIt(): void
    {
        $this->fixture->pages->published('Live', [], $this->news);
        $this->news->changeState(PageState::Trash, new \DateTimeImmutable());

        $response = $this->send($this->editor, $this->news, 'POST');

        self::assertSame('/admin/pages/' . $this->news->id(), $response->getHeaderLine('Location'));
        self::assertSame(['Only pages in the trash can be deleted for good, with every page under them.'], array_map(strval(...), Flash::take($this->fixture->session)));
    }

    public function testOnlyEditorsDeletePagesInTheTrash(): void
    {
        $contributor = $this->fixture->admin->auth->user('cleo@example.org', 'contributor');
        try {
            $this->send($this->editor, $this->news, 'GET');
            self::fail('A published page is not in the trash.');
        } catch (HttpForbiddenException) {
        }
        $draft = $this->fixture->pages->editor()->create('en-GB', new PageContent('Draft'));
        $draft->changeState(PageState::Trash, new \DateTimeImmutable());

        $this->expectException(HttpNotFoundException::class);
        $this->send($contributor, $draft, 'GET');
    }

    private function send(User $user, Page $page, string $method): ResponseInterface
    {
        return $this->fixture->handle($this->fixture->purgeHandler(), $user, $method, '/admin/pages/x/delete', [], (int) $page->id());
    }
}
