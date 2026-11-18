<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Page;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\PageRedirects;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * Page operations (P-1) through a real site: moving a branch leaves one-hop 301s, a new page on an old address wins,
 * the trash answers 410 until the page is taken out again, deleting for good removes the revisions and leaves the
 * address gone, copies keep every translation, and every step is in the audit log and out of the sitemap.
 */
#[CoversNothing]
final class PageOperationsTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('page_operations', locales: ['en-GB', 'pt-PT']);
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testMovesTrashesRestoresCopiesAndDeletesPages(): void
    {
        $container = $this->site->container();
        $editor = Services::get($container, PageEditor::class);
        $workflow = Services::get($container, PageWorkflow::class);
        $pages = Services::get($container, PageRepository::class);
        $actor = $this->editor();
        $now = new DateTimeImmutable();
        $home = $editor->create('en-GB', new PageContent('Welcome', 'home'));
        $a = $editor->create('en-GB', new PageContent('A', 'a'));
        $b = $editor->create('en-GB', new PageContent('B', 'b'), $a);
        $c = $editor->create('en-GB', new PageContent('C', 'c'));
        $editor->save($a, 'pt-PT', new PageContent('A pt', 'a-pt'));
        $editor->publishTranslation($a, 'pt-PT', true);
        foreach ([$home, $a, $b, $c] as $page) {
            $page->changeState(PageState::Published, $now);
        }
        $pages->flush();
        $browser = new Browser($this->site->kernel, 'https://example.org');
        self::assertSame(200, $browser->request('GET', '/a/b')->getStatusCode());

        $editor->move($b, $c, 0, $actor->userId);
        $moved = $browser->request('GET', '/a/b');
        self::assertSame([301, 'https://example.org/c/b'], [$moved->getStatusCode(), $moved->getHeaderLine('Location')]);
        self::assertSame(200, $browser->request('GET', '/c/b')->getStatusCode());
        $editor->move($b, null, 9, $actor->userId);
        self::assertSame('https://example.org/b', $browser->request('GET', '/a/b')->getHeaderLine('Location'), 'one hop');
        self::assertSame('https://example.org/b', $browser->request('GET', '/c/b')->getHeaderLine('Location'));

        $fresh = $editor->create('en-GB', new PageContent('New C/B', 'b'), $c);
        $fresh->changeState(PageState::Published, $now);
        $pages->flush();
        self::assertSame(200, $browser->request('GET', '/c/b')->getStatusCode(), 'a new page on an old address wins');

        $workflow->apply($b, PageTransition::Trash, $actor);
        self::assertSame(410, $browser->request('GET', '/b')->getStatusCode());
        self::assertStringNotContainsString('<loc>https://example.org/b</loc>', (string) $browser->request('GET', '/sitemap-en-gb.xml')->getBody());
        $workflow->apply($b, PageTransition::Untrash, $actor);
        $workflow->apply($b, PageTransition::Archive, $actor);
        self::assertSame(404, $browser->request('GET', '/b')->getStatusCode(), 'archived pages are not found');
        $workflow->apply($b, PageTransition::Restore, $actor);
        $b->changeState(PageState::Published, $now);
        $pages->flush();
        self::assertSame(200, $browser->request('GET', '/b')->getStatusCode(), 'back after the trash');

        $copy = $editor->copy($a, $actor->userId);
        self::assertSame(['Copy of A', 'Cópia de A pt'], [$copy->translation('en-GB')?->title(), $copy->translation('pt-PT')?->title()]);
        self::assertSame(['a-copy', 'a-pt-copy'], [$copy->translation('en-GB')?->path(), $copy->translation('pt-PT')?->path()]);

        $workflow->apply($b, PageTransition::Trash, $actor);
        $id = (int) $b->id();
        $editor->purge($b, $actor->userId);
        $em = Services::get($container, EntityManagerInterface::class);
        $em->clear();
        self::assertNull($pages->find($id));
        self::assertEquals(0, $em->getConnection()->createQueryBuilder()->select('COUNT(*)')->from('page_revisions')->where('page_id = :id')->setParameter('id', $id)->fetchOne());
        self::assertSame(410, $browser->request('GET', '/b')->getStatusCode());
        self::assertSame(410, $browser->request('GET', '/a/b')->getStatusCode(), 'redirects to a deleted page are gone too');
        self::assertTrue(Services::get($container, PageRedirects::class)->find('en-GB', 'a/b')?->isGone());

        $actions = array_map(static fn ($entry): string => $entry->action, Services::get($container, AuditLog::class)->find(new AuditFilter(), 100));
        foreach (['page.moved', 'page.trash', 'page.untrash', 'page.archive', 'page.restore', 'page.cloned', 'page.purged'] as $action) {
            self::assertContains($action, $actions);
        }

        $this->expectException(PageException::class);
        $editor->create('en-GB', new PageContent('Search', 'search'));
    }

    private function editor(): Actor
    {
        $container = $this->site->container();
        $user = new User('ed@example.org', 'Ed', 'Itor', 'UTC', new DateTimeImmutable());
        $user->setPasswordHash('$argon2id$v=19$m=8,t=1,p=1$c2FsdHNhbHQ$aGFzaA', new DateTimeImmutable());
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole('editor') ?? self::fail('editor'));

        return new Actor((int) $user->id(), $roles->permissionNamesOf((int) $user->id()));
    }
}
