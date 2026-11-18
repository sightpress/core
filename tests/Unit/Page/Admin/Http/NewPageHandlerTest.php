<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Page\Admin\Http\NewPageHandler;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tests\Support\PageAdminFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;

#[CoversClass(NewPageHandler::class)]
#[CoversClass(PageAdmin::class)]
#[UsesNamespace('SightPress\Core')]
final class NewPageHandlerTest extends TestCase
{
    private PageAdminFixture $fixture;

    private User $contributor;

    protected function setUp(): void
    {
        $this->fixture = new PageAdminFixture();
        $this->contributor = $this->fixture->admin->auth->user('cleo@example.org', 'contributor');
    }

    public function testOnlyPeopleWhoCreatePagesMayStartOne(): void
    {
        $this->expectException(HttpForbiddenException::class);

        $this->fixture->handle($this->fixture->newHandler(), $this->fixture->admin->auth->user('max@example.org', 'member'), 'GET', NewPageHandler::PATH);
    }

    public function testAsksForTheTitlePlaceAndLayout(): void
    {
        $about = $this->fixture->pages->published('About');
        $deep = $about;
        for ($depth = 1; $depth < Page::MAX_DEPTH; ++$depth) {
            $deep = $this->fixture->pages->editor()->create('en-GB', new PageContent('Level ' . $depth), $deep);
        }

        $html = (string) $this->fixture->handle($this->fixture->newHandler(), $this->contributor, 'GET', NewPageHandler::PATH)->getBody();

        self::assertStringContainsString('<h1>Start a new page</h1>', $html);
        self::assertStringContainsString('<option value="top" selected>None: a top-level page</option>', $html);
        self::assertStringContainsString('>About</option>', $html);
        self::assertStringContainsString('>— Level 1</option>', $html);
        self::assertStringNotContainsString('Level 9</option>', $html, 'nothing can go under the deepest page');
        self::assertStringContainsString('Home page: the title and summary as a large introduction', $html);
    }

    public function testStartsADraftAndOpensItInTheEditor(): void
    {
        $about = $this->fixture->pages->published('About');

        $response = $this->fixture->handle($this->fixture->newHandler(), $this->contributor, 'POST', NewPageHandler::PATH, ['title' => 'Our team', 'parent' => 'page-' . $about->id(), 'layout' => 'home']);

        self::assertSame(303, $response->getStatusCode());
        $page = $this->fixture->pages->repository()->findByPath('en-GB', 'about/our-team')?->page();
        self::assertNotNull($page);
        self::assertSame(PageAdmin::editPath((int) $page->id()), $response->getHeaderLine('Location'));
        self::assertSame(['home', PageState::Draft, (int) $this->contributor->id()], [$page->layout(), $page->state(), $page->authorId()]);
        self::assertSame(['“Our team” has been started as a draft. Add its content, then save it.'], array_map(strval(...), Flash::take($this->fixture->session)));

        $top = $this->fixture->handle($this->fixture->newHandler(), $this->contributor, 'POST', NewPageHandler::PATH, ['title' => 'Events', 'parent' => 'top', 'layout' => 'default']);
        self::assertSame(303, $top->getStatusCode());
        self::assertSame('default', $this->fixture->pages->repository()->findByPath('en-GB', 'events')?->page()->layout());
    }

    public function testExplainsWhatIsWrong(): void
    {
        $missing = $this->fixture->handle($this->fixture->newHandler(), $this->contributor, 'POST', NewPageHandler::PATH, ['title' => '', 'parent' => 'top', 'layout' => 'default']);
        self::assertSame(422, $missing->getStatusCode());
        self::assertStringContainsString('1 error:', (string) $missing->getBody());

        $reserved = $this->fixture->handle($this->fixture->newHandler(), $this->contributor, 'POST', NewPageHandler::PATH, ['title' => 'Admin', 'parent' => 'top', 'layout' => 'default']);
        self::assertSame(422, $reserved->getStatusCode());
        self::assertStringContainsString('The address &quot;admin&quot; is used by the system.', (string) $reserved->getBody());
    }
}
