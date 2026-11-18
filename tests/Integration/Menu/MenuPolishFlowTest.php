<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Menu;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\MenuEditor;
use SightPress\Core\Page\Entity\Page;
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
 * Menus and breadcrumbs polish through the kernel (MN-1): an editor gives an entry its Portuguese text and makes
 * another for members only on the entry's screen; Portuguese pages show the translated texts (or the page's
 * Portuguese title) with `aria-current`; members pages and members-only entries appear once someone signs in; a link
 * to an address whose page moved leads to the new address; deleting a page for good removes its entry.
 */
#[CoversNothing]
final class MenuPolishFlowTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    /** @var array<string, Page> */
    private array $pages = [];

    protected function setUp(): void
    {
        $this->site = new KernelSite('menu-polish', ['example.org'], 'test', ['en-GB', 'pt-PT']);
        $container = $this->site->container();
        $editor = Services::get($container, PageEditor::class);
        $now = new DateTimeImmutable();
        $this->pages['home'] = $editor->create('en-GB', new PageContent('Welcome', 'home'));
        $this->pages['about'] = $editor->create('en-GB', new PageContent('About us', 'about'));
        $editor->save($this->pages['about'], 'pt-PT', new PageContent('Sobre nós', 'sobre'));
        $editor->publishTranslation($this->pages['about'], 'pt-PT', true);
        $this->pages['team'] = $editor->create('en-GB', new PageContent('Our team', 'team'), $this->pages['about']);
        $this->pages['club'] = $editor->create('en-GB', new PageContent('Book club', 'club'));
        $this->pages['club']->setPublic(false, $now);
        $this->pages['old'] = $editor->create('en-GB', new PageContent('Old news', 'old'));
        foreach (['home', 'about', 'team', 'club', 'old'] as $key) {
            $this->pages[$key]->changeState(PageState::Published, $now);
        }
        Services::get($container, PageRepository::class)->flush();
        foreach (['bea@example.org' => ['Bea', 'editor'], 'max@example.org' => ['Max', 'member']] as $email => [$name, $role]) {
            $user = new User($email, $name, 'Tester', 'Europe/London', $now);
            $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), $now);
            Services::get($container, UserRepository::class)->save($user);
            $roles = Services::get($container, RoleRepository::class);
            $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));
        }
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testTranslatedLabelsMembersEntriesMovedLinksAndDeletedPages(): void
    {
        $menus = Services::get($this->site->container(), MenuEditor::class);
        $about = $menus->add(MenuName::Main, (int) $this->pages['about']->id());
        $menus->add(MenuName::Main, (int) $this->pages['club']->id());
        $forum = $menus->add(MenuName::Main, null, 'Forum', 'https://forum.example');
        $team = $menus->add(MenuName::Footer, null, 'The team', '/about/team');
        $menus->add(MenuName::Footer, (int) $this->pages['old']->id());

        $bea = $this->signedIn('bea@example.org');
        $list = (string) $bea->request('GET', '/admin/menus')->getBody();
        self::assertStringContainsString('not shown in these languages until the page is published in them: European Portuguese', $list, 'the club page has no Portuguese translation');
        self::assertSame(303, $bea->submit('/admin/menus/' . $about->id(), ['label' => '', 'label_pt_pt' => 'Quem somos'])->getStatusCode());
        self::assertSame(303, $bea->submit('/admin/menus/' . $forum->id(), ['label' => 'Forum', 'label_pt_pt' => 'Fórum', 'members_only' => '1'])->getStatusCode());
        self::assertStringContainsString('only shown to members who are signed in', (string) $bea->request('GET', '/admin/menus')->getBody());

        $visitor = new Browser($this->site->kernel, 'https://example.org');
        $english = (string) $visitor->request('GET', '/about')->getBody();
        self::assertStringContainsString('<a href="/about" aria-current="page">About us</a>', $english);
        self::assertStringNotContainsString('Book club', $english, 'a members page is hidden from visitors');
        self::assertStringNotContainsString('Forum', $english, 'and so is a members-only entry');
        $portuguese = (string) $visitor->request('GET', '/pt-pt/sobre')->getBody();
        self::assertStringContainsString('<a href="/pt-pt/sobre" aria-current="page">Quem somos</a>', $portuguese);
        self::assertStringNotContainsString('Old news', $portuguese, 'no Portuguese translation, no entry');

        $max = $this->signedIn('max@example.org');
        $member = (string) $max->request('GET', '/about')->getBody();
        self::assertStringContainsString('<a href="/club">Book club</a>', $member);
        self::assertStringContainsString('<a href="https://forum.example">Forum</a>', $member);
        self::assertStringContainsString('<a href="https://forum.example">Fórum</a>', (string) $max->request('GET', '/pt-pt/sobre')->getBody());

        $editor = Services::get($this->site->container(), PageEditor::class);
        $editor->move($this->pages['team'], null, 5);
        $moved = (string) $visitor->request('GET', '/about')->getBody();
        self::assertStringContainsString('<a href="/team">The team</a>', $moved, 'the link follows the page to its new address');
        self::assertSame('/about/team', $menus->find((int) $team->id())?->url(), 'the entry itself is unchanged');

        $this->pages['old']->changeState(PageState::Trash, new DateTimeImmutable());
        $editor->purge($this->pages['old']);
        self::assertStringNotContainsString('Old news', (string) $bea->request('GET', '/admin/menus')->getBody());
    }

    private function signedIn(string $email): Browser
    {
        $browser = new Browser($this->site->kernel, 'https://example.org');
        $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD]);

        return $browser;
    }
}
