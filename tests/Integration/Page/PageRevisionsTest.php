<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Page;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * Revisions (P-2) through the whole kernel: an editor sees the versions of a page in their time zone, compares one
 * with the version before it (changes said in words, with <del> and <ins>), restores an older one as a new version
 * (the page stays a draft) and finds it in the audit log; a published translation can't be replaced that way.
 */
#[CoversNothing]
final class PageRevisionsTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('page-revisions');
        $container = $this->site->container();
        $now = new DateTimeImmutable();
        $user = new User('bea@example.org', 'Bea', 'Tester', 'Pacific/Auckland', $now);
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), $now);
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole('editor') ?? throw new \LogicException('editor'));
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testComparesAndRestoresVersions(): void
    {
        $container = $this->site->container();
        $editor = Services::get($container, PageEditor::class);
        $page = $editor->create('en-GB', new PageContent('Opening hours', 'hours', 'Open from 9 to 5.'));
        $editor->save($page, 'en-GB', new PageContent('Opening times', 'hours', 'Open from 10 to 4.'));
        $id = (int) $page->id();

        $bea = new Browser($this->site->kernel, 'https://example.org');
        $bea->submit('/login', ['email' => 'bea@example.org', 'password' => self::PASSWORD]);
        self::assertStringContainsString('<a href="/admin/pages/' . $id . '/revisions">Older versions</a>', (string) $bea->request('GET', '/admin/pages/' . $id)->getBody());

        $list = (string) $bea->request('GET', '/admin/pages/' . $id . '/revisions')->getBody();
        self::assertStringContainsString('Versions of “Opening times”', $list);
        self::assertSame(1, preg_match('/<time datetime="([^"]+)">([^<]+)<\/time>/', $list, $time));
        $local = new DateTimeImmutable($time[1])->setTimezone(new \DateTimeZone('Pacific/Auckland'));
        self::assertStringContainsString($local->format('H:i'), $time[2], 'times are shown in the viewer’s time zone');

        $diff = (string) $bea->request('GET', '/admin/pages/' . $id . '/revisions/2')->getBody();
        self::assertStringContainsString('<span class="diff__mark">[removed:</span> <del>9</del><span class="diff__mark">]</span>', $diff);
        self::assertStringContainsString('<span class="diff__mark">[added:</span> <ins>10</ins><span class="diff__mark">]</span>', $diff);

        $restored = $bea->submit('/admin/pages/' . $id . '/revisions/1', [], '/admin/pages/' . $id . '/revisions/1');
        self::assertSame([303, '/admin/pages/' . $id], [$restored->getStatusCode(), $restored->getHeaderLine('Location')], (string) $restored->getBody());
        $pages = Services::get($this->site->container(), PageRepository::class);
        $page = $pages->find($id) ?? self::fail('The page is gone.');
        self::assertSame(['Opening hours', PageState::Draft], [$page->translation('en-GB')?->title(), $page->state()]);
        self::assertCount(3, $pages->revisions($page));
        self::assertContains('page.revision_restored', array_map(static fn ($entry): string => $entry->action, Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(), 20)));

        $page->changeState(PageState::Published, new DateTimeImmutable());
        $pages->flush();
        $refused = $bea->submit('/admin/pages/' . $id . '/revisions/2', [], '/admin/pages/' . $id . '/revisions/2');
        self::assertSame(303, $refused->getStatusCode());
        self::assertSame('Opening hours', $pages->find($id)?->translation('en-GB')?->title(), 'a published translation is never replaced');
    }
}
