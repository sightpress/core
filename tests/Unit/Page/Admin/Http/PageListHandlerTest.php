<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Admin\Http\PageListHandler;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Tests\Support\PageAdminFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;

#[CoversClass(PageListHandler::class)]
#[CoversClass(PageAdmin::class)]
#[UsesNamespace('SightPress\Core')]
final class PageListHandlerTest extends TestCase
{
    private PageAdminFixture $fixture;

    private User $editor;

    private User $contributor;

    protected function setUp(): void
    {
        $this->fixture = new PageAdminFixture();
        $this->editor = $this->fixture->admin->auth->user('bea@example.org', 'editor');
        $this->contributor = $this->fixture->admin->auth->user('cleo@example.org', 'contributor');
    }

    public function testOnlyPeopleWhoWritePagesMayUseIt(): void
    {
        $this->expectException(HttpForbiddenException::class);

        $this->list($this->fixture->admin->auth->user('max@example.org', 'member'));
    }

    public function testSaysWhenThereAreNoPages(): void
    {
        $html = $this->list($this->editor);

        self::assertStringContainsString('<p>There are no pages yet.</p>', $html);
        self::assertStringContainsString('<a class="button" href="/admin/pages/new">Start a new page</a>', $html);
        self::assertStringContainsString('js/page-admin.js', $html);
    }

    public function testShowsEveryPageAsATreeWithItsStateAddressAndWriter(): void
    {
        $editor = $this->fixture->pages->editor();
        $about = $this->fixture->pages->published('About');
        $this->fixture->pages->addresses()->setHome($about);
        $editor->create('en-GB', new PageContent('Team'), $about, (int) $this->contributor->id());
        $editor->create('en-GB', new PageContent('Draft by Bea'), null, (int) $this->editor->id());

        $html = $this->list($this->editor);

        self::assertStringContainsString('All pages (3)', $html);
        self::assertMatchesRegularExpression('#<a href="/admin/pages/\d+" lang="en-GB" data-page-title>About</a>\s*<span class="page-list__meta">Published · Home page · <span class="page-list__url">/</span>#', $html);
        self::assertMatchesRegularExpression('#<ul class="page-tree" id="page-tree-\d+">\s*<li>\s*<a href="/admin/pages/\d+" lang="en-GB" data-page-title>Team</a>#', $html);
        self::assertStringContainsString('Written by Ada', $html, 'the contributor fixture is named Ada');
        self::assertStringContainsString('Written by you', $html);
        self::assertStringContainsString('<span class="page-list__url">/about/team</span>', $html);
    }

    public function testWritersSeeTheirOwnDraftsAndPublishedPagesOnly(): void
    {
        $editor = $this->fixture->pages->editor();
        $hidden = $editor->create('en-GB', new PageContent('Secret plans'), null, (int) $this->editor->id());
        $editor->create('en-GB', new PageContent('My notes'), $hidden, (int) $this->contributor->id());
        $editor->create('en-GB', new PageContent('Other draft'), null, (int) $this->editor->id());

        $html = $this->list($this->contributor);

        self::assertStringContainsString('All pages (1)', $html);
        self::assertStringContainsString('<span class="page-list__hidden">A page you can’t open</span>', $html);
        self::assertStringNotContainsString('Secret plans', $html);
        self::assertStringNotContainsString('Other draft', $html);
        self::assertStringContainsString('My notes', $html);
        self::assertStringNotContainsString('Start a new page</a>', $this->list($this->fixture->admin->auth->user('pub@example.org', null), ['page.publish']));

        $filtered = $this->list($this->contributor, [], '?mine=1');
        self::assertStringContainsString('1 page matches', $filtered);
        self::assertStringContainsString('Under: a page you can’t open', $filtered);
    }

    public function testFiltersByStateTextAndWriter(): void
    {
        $editor = $this->fixture->pages->editor();
        $about = $this->fixture->pages->published('About us');
        $editor->create('en-GB', new PageContent('Team'), $about, (int) $this->editor->id());
        $editor->create('en-GB', new PageContent('Opening hours'), null, (int) $this->contributor->id());

        $drafts = $this->list($this->editor, [], '?state=draft&q=TEAM');
        self::assertStringContainsString('1 page matches', $drafts);
        self::assertStringContainsString('Under: About us', $drafts);
        self::assertStringContainsString('<option value="draft" selected>Draft</option>', $drafts);
        self::assertStringContainsString('value="TEAM"', $drafts);
        self::assertStringContainsString('<a href="/admin/pages">Show all pages</a>', $drafts);

        self::assertStringContainsString('0 pages match', $this->list($this->editor, [], '?state=published&mine=1'));
        self::assertStringContainsString('2 pages match', $this->list($this->editor, [], '?state=draft'));
        self::assertStringContainsString('All pages (3)', $this->list($this->editor, [], '?state=nonsense'));
    }

    public function testKnowsItsAddressesAndStates(): void
    {
        self::assertSame('/admin/pages/4', PageAdmin::editPath(4));
        self::assertSame('/admin/pages/4/workflow', PageAdmin::workflowPath(4));
        $labels = array_map(PageAdmin::stateLabel(...), \SightPress\Core\Rbac\Contract\PageState::cases());
        self::assertSame(['Draft', 'Waiting for review', 'Approved', 'Published', 'Archived', 'In the trash'], $labels);
    }

    /**
     * @param list<string> $permissions extra permissions for a person without a role
     */
    private function list(User $user, array $permissions = [], string $query = ''): string
    {
        $request = $this->fixture->admin->request($user, 'GET', '/admin/pages' . $query, [], $this->fixture->session);
        if ($permissions !== []) {
            $request = $request->withAttribute(\SightPress\Core\Rbac\Contract\Actor::class, new \SightPress\Core\Rbac\Contract\Actor((int) $user->id(), $permissions));
        }

        return (string) $this->fixture->listHandler()->handle($request)->getBody();
    }
}
