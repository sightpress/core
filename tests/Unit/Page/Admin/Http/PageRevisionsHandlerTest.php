<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Page\Admin\Http\PageRevisionHandler;
use SightPress\Core\Page\Admin\Http\PageRevisionsHandler;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tests\Support\PageAdminFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(PageRevisionsHandler::class)]
#[CoversClass(PageRevisionHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class PageRevisionsHandlerTest extends TestCase
{
    private PageAdminFixture $fixture;

    private User $editor;

    private Page $page;

    protected function setUp(): void
    {
        $this->fixture = new PageAdminFixture();
        $this->editor = $this->fixture->admin->auth->user('bea@example.org', 'editor');
        $editor = $this->fixture->pages->editor();
        $this->page = $editor->create('en-GB', new PageContent('About', 'about', 'The quick fox'), null, (int) $this->editor->id());
        $editor->save($this->page, 'en-GB', new PageContent('About us', 'about', 'The slow fox', null, [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Team', 'level' => 2]]]), (int) $this->editor->id());
        $editor->save($this->page, 'pt-PT', new PageContent('Sobre', 'sobre'));
    }

    public function testListsTheVersionsOfATranslationWithWhoAndWhen(): void
    {
        $html = $this->list($this->editor);

        self::assertStringContainsString('<h1>Versions of “About us” in British English</h1>', $html);
        self::assertStringContainsString('<a href="/admin/pages/' . $this->page->id() . '/revisions/2">Version 2</a> (the current one)', $html);
        self::assertStringContainsString('<a href="/admin/pages/' . $this->page->id() . '/revisions/1">Version 1 (the first)</a>', $html);
        self::assertStringContainsString('<td>Ada</td>', $html);
        self::assertStringContainsString('<td>Draft</td>', $html);
        self::assertStringContainsString('<time datetime="2026-11-14T12:00:00Z">', $html);
        self::assertStringContainsString('<a href="/admin/pages/' . $this->page->id() . '/revisions?locale=pt-PT">', $html);
        self::assertStringContainsString('<a href="/admin/pages/' . $this->page->id() . '/revisions" aria-current="page">', $html);

        $portuguese = $this->list($this->editor, '?locale=pt-PT');
        self::assertStringContainsString('<a href="/admin/pages/' . $this->page->id() . '/revisions/3?locale=pt-PT">Version 3 (the first)</a>', $portuguese);
        self::assertStringContainsString('<td>The system</td>', $portuguese);
    }

    public function testExplainsWhenThereIsNoVersionAndTheNamesItCantShow(): void
    {
        $fixture = new PageAdminFixture(['en-GB']);
        $editor = $fixture->admin->auth->user('bea@example.org', 'editor');
        $page = $fixture->pages->editor()->create('en-GB', new PageContent('Solo'), null, 999);
        $snapshot = new \ReflectionProperty(\SightPress\Core\Page\Entity\PageRevision::class, 'snapshot');
        $snapshot->setValue($fixture->pages->repository()->revisions($page)[0], ['translations' => ['en-GB' => ['title' => 'Solo']]]);

        $html = (string) $fixture->handle($fixture->revisionsHandler(), $editor, 'GET', '/admin/pages/' . $page->id() . '/revisions', id: (int) $page->id())->getBody();

        self::assertStringContainsString('<h1>Versions of “Solo”</h1>', $html);
        self::assertStringNotContainsString('Versions in other languages', $html);
        self::assertStringContainsString('<td>An account that no longer exists</td>', $html);
        self::assertStringContainsString('<td>Not known</td>', $html);

        $empty = (string) $this->fixture->handle($this->fixture->revisionsHandler(), $this->editor, 'GET', '/admin/pages/' . $this->page->id() . '/revisions?locale=pt-PT', id: (int) $this->fixture->pages->editor()->create('en-GB', new PageContent('Fresh'))->id())->getBody();
        self::assertStringContainsString('<h1>Versions of “Fresh” in European Portuguese</h1>', $empty);
        self::assertStringContainsString('The page isn’t written in this language yet, so it has no versions in it.', $empty);
    }

    public function testComparesAVersionWithTheOneBeforeIt(): void
    {
        $html = $this->show($this->editor, 2);

        self::assertStringContainsString('<h1>Version 2 of “About us”</h1>', $html);
        self::assertStringContainsString('<h2 id="changes-title">What changed since version 1</h2>', $html);
        self::assertStringContainsString('<dt>Title</dt>', $html);
        self::assertStringContainsString('<span>About</span> <span class="diff__mark">[added:</span> <ins>us</ins><span class="diff__mark">]</span>', $html);
        self::assertStringContainsString('<span class="diff__mark">[removed:</span> <del>quick</del><span class="diff__mark">]</span>', $html);
        self::assertStringContainsString('Block 1 of 1: Heading <span class="diff__status">(Added)</span>', $html);
        self::assertStringContainsString('About the page <span class="diff__status">(Changed)</span>', $html);
        self::assertStringContainsString('<button type="submit">Restore version 2</button>', $html);
        self::assertStringContainsString('State of the page then: Draft', $html);

        $first = $this->show($this->editor, 1);
        self::assertStringContainsString('The first version in this language', $first);
        self::assertStringContainsString('Everything in it was new.', $first);

        $same = $this->show($this->editor, 3);
        self::assertStringContainsString('Nothing a reader would notice changed in this version.', $same);
        self::assertStringContainsString('By The system', $same);
        self::assertStringContainsString('<span class="diff__status">(No changes)</span>', $same);
    }

    public function testRestoresAVersionAsANewOneAndRefusesWhatWouldPublish(): void
    {
        $response = $this->send($this->editor, 1, 'POST');

        self::assertSame([303, PageAdmin::editPath((int) $this->page->id())], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
        self::assertSame(['Restored version 1 as version 4. Nothing was published: the page is still draft.'], array_map(strval(...), Flash::take($this->fixture->session)));
        self::assertSame('About', $this->page->translation('en-GB')?->title());

        $this->page->changeState(PageState::Published, new \DateTimeImmutable());
        $html = $this->show($this->editor, 2);
        self::assertStringContainsString('Visitors can read this translation now, and restoring never publishes.', $html);
        self::assertStringNotContainsString('<button type="submit">Restore', $html);
        $refused = $this->send($this->editor, 2, 'POST');
        self::assertSame(PageRevisionHandler::path((int) $this->page->id(), 2, 'en-GB', 'en-GB'), $refused->getHeaderLine('Location'));
        self::assertStringStartsWith('Visitors can read this translation now', (string) Flash::take($this->fixture->session)[0]);

        $portuguese = $this->send($this->editor, 3, 'POST', '?locale=pt-PT');
        self::assertSame(PageAdmin::editPath((int) $this->page->id()) . '?locale=pt-PT', $portuguese->getHeaderLine('Location'));
        self::assertSame(['Restored version 3 as version 5. Nothing was published: the page is still published.'], array_map(strval(...), Flash::take($this->fixture->session)));
    }

    public function testRefusesBlocksThatNoLongerPassTheChecks(): void
    {
        $revision = $this->fixture->pages->repository()->revision($this->page, 2);
        self::assertNotNull($revision);
        new \ReflectionProperty(\SightPress\Core\Page\Entity\PageRevision::class, 'snapshot')->setValue($revision, ['translations' => ['en-GB' => ['title' => 'About', 'blocks' => [['type' => 'heading', 'version' => 1, 'data' => ['level' => 9]]]]]]);

        $response = $this->send($this->editor, 2, 'POST');

        self::assertSame(PageRevisionHandler::path((int) $this->page->id(), 2, 'en-GB', 'en-GB'), $response->getHeaderLine('Location'));
        self::assertSame(['Version 2 can’t be restored: some of its blocks no longer pass the checks. Copy what you need from it instead.'], array_map(strval(...), Flash::take($this->fixture->session)));
    }

    public function testOnlyPeopleWhoMayChangeThePageRestoreIt(): void
    {
        $contributor = $this->fixture->admin->auth->user('cy@example.org', 'contributor');
        $page = $this->fixture->pages->editor()->create('en-GB', new PageContent('Mine'), null, (int) $contributor->id());
        $request = $this->fixture->admin->request($contributor, 'GET', '/admin/pages/' . $page->id() . '/revisions/1', [], $this->fixture->session)
            ->withAttribute(PageAdmin::ID_ATTRIBUTE, (string) $page->id())
            ->withAttribute(PageRevisionHandler::NUMBER_ATTRIBUTE, '1');
        self::assertStringContainsString('Restore version 1', (string) $this->fixture->revisionHandler()->handle($request)->getBody());
        $page->changeState(PageState::InReview, new \DateTimeImmutable());
        $html = (string) $this->fixture->revisionHandler()->handle($request)->getBody();
        self::assertStringNotContainsString('Restore this version', $html);

        $this->expectException(HttpForbiddenException::class);
        $this->fixture->revisionHandler()->handle($request->withMethod('POST'));
    }

    public function testUnknownVersionsLanguagesPagesAndPeople(): void
    {
        foreach ([
            fn () => $this->show($this->editor, 9),
            fn () => $this->show($this->editor, 3, '?locale=es'),
            fn () => $this->show($this->editor, 1, '?locale=pt-PT'),
            fn () => $this->list($this->editor, '?locale=es'),
            fn () => $this->list($this->fixture->admin->auth->user('cy@example.org', 'contributor')),
        ] as $index => $call) {
            try {
                $call();
                self::fail('Case ' . $index . ' was shown.');
            } catch (HttpNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $request = $this->fixture->admin->request($this->editor, 'GET', '/admin/pages/1/revisions/x', [], $this->fixture->session)
            ->withAttribute(PageAdmin::ID_ATTRIBUTE, (string) $this->page->id())
            ->withAttribute(PageRevisionHandler::NUMBER_ATTRIBUTE, 'x');
        try {
            $this->fixture->revisionHandler()->handle($request);
            self::fail('A version without a number was shown.');
        } catch (HttpNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(HttpForbiddenException::class);
        $this->list($this->fixture->admin->auth->user('mo@example.org', 'member'));
    }

    private function list(User $user, string $query = ''): string
    {
        return (string) $this->fixture->handle($this->fixture->revisionsHandler(), $user, 'GET', '/admin/pages/' . $this->page->id() . '/revisions' . $query, id: (int) $this->page->id())->getBody();
    }

    private function show(User $user, int $number, string $query = ''): string
    {
        return (string) $this->send($user, $number, 'GET', $query)->getBody();
    }

    private function send(User $user, int $number, string $method, string $query = ''): ResponseInterface
    {
        $request = $this->fixture->admin->request($user, $method, '/admin/pages/' . $this->page->id() . '/revisions/' . $number . $query, [], $this->fixture->session)
            ->withAttribute(PageAdmin::ID_ATTRIBUTE, (string) $this->page->id())
            ->withAttribute(PageRevisionHandler::NUMBER_ATTRIBUTE, (string) $number);

        return $this->fixture->revisionHandler()->handle($request);
    }
}
