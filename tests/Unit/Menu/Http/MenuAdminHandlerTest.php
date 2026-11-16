<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Menu\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\Http\MenuAdminHandler;
use SightPress\Core\Menu\MenuEditor;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageLinkQuery;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;

#[CoversClass(MenuAdminHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class MenuAdminHandlerTest extends TestCase
{
    private AdminFixture $fixture;

    private PageFixture $pages;

    private MenuEditor $menus;

    private User $editor;

    private Session $session;

    protected function setUp(): void
    {
        $this->fixture = new AdminFixture();
        $this->pages = new PageFixture($this->fixture->auth->database);
        $this->menus = new MenuEditor($this->fixture->auth->database->entityManager, new RecordingEvents());
        $this->editor = $this->fixture->auth->user();
        $this->session = new Session();
    }

    public function testOnlyPeopleWhoManageMenusMayUseIt(): void
    {
        $this->expectException(HttpForbiddenException::class);

        $this->handle('GET', [], $this->fixture->auth->user('cleo@example.org', 'contributor'));
    }

    public function testShowsEmptyMenusAndTheAddForms(): void
    {
        $html = (string) $this->handle('GET')->getBody();

        self::assertSame(2, substr_count($html, 'This menu is empty. Add a page or a link below.'));
        self::assertStringContainsString('<p>There are no pages yet.</p>', $html);
        self::assertStringContainsString('<h2 id="menu-main" tabindex="-1">Main menu</h2>', $html);
    }

    public function testAddsPagesAndLinksAndExplainsMistakes(): void
    {
        $about = $this->pages->published('About');
        $team = $this->pages->published('Team', [], $about);
        $this->pages->editor()->create('en-GB', new PageContent('Draft'));

        $form = (string) $this->handle('GET')->getBody();
        self::assertStringContainsString('>— Team</option>', $form);
        self::assertStringContainsString('>Draft (not published)</option>', $form);

        $added = $this->handle('POST', ['action' => 'add-page', 'menu' => 'main', 'page' => 'page-' . $team->id()]);
        self::assertSame(303, $added->getStatusCode());
        self::assertSame(['Team has been added to the main menu.'], Flash::take($this->session));
        $item = $this->menus->items(MenuName::Main)[0];
        self::assertStringContainsString('<span id="item-' . $item->id() . '" tabindex="-1" autofocus>Team</span>', (string) $this->handle('GET')->getBody());
        self::assertStringNotContainsString('autofocus', (string) $this->handle('GET')->getBody(), 'focus is moved once');

        $this->handle('POST', ['action' => 'add-link', 'menu' => 'footer', 'label' => 'Hours', 'url' => '/hours']);
        self::assertSame(['Hours has been added to the footer menu.'], Flash::take($this->session));

        $missing = $this->handle('POST', ['action' => 'add-page', 'page' => 'page-' . $team->id()]);
        self::assertSame(422, $missing->getStatusCode());
        self::assertStringContainsString('Select menu', (string) $missing->getBody());
        $badLink = $this->handle('POST', ['action' => 'add-link', 'menu' => 'footer', 'label' => 'Bad', 'url' => 'javascript:alert(1)']);
        self::assertSame(422, $badLink->getStatusCode());
        self::assertStringContainsString('The address must start with https://', (string) $badLink->getBody());
        self::assertSame(422, $this->handle('POST', ['action' => 'add-link', 'menu' => 'footer'])->getStatusCode());

        $this->expectException(HttpBadRequestException::class);
        $this->handle('POST', ['action' => 'nonsense']);
    }

    public function testMovesAndRemovesEntriesAndPutsFocusBack(): void
    {
        $about = $this->pages->published('About');
        $team = $this->pages->published('Team', [], $about);
        $a = $this->menus->add(MenuName::Main, (int) $about->id());
        $b = $this->menus->add(MenuName::Main, (int) $team->id());
        $c = $this->menus->add(MenuName::Main, null, 'Hours', '/hours');
        $d = $this->menus->add(MenuName::Main, 9999);
        $this->menus->add(MenuName::Main, null, 'Late opening', '/late', parent: $c);

        $page = (string) $this->handle('GET')->getBody();
        self::assertStringContainsString('Missing page (#9999)', $page);
        self::assertStringContainsString('Page: /about', $page);
        self::assertStringContainsString('Link: /hours', $page);
        self::assertStringContainsString('aria-label="Move down, About">Move down</button>', $page);
        self::assertStringNotContainsString('aria-label="Move up, About"', $page);

        foreach ([
            ['in', $b, 'Team has been moved in, under the entry before it.', 'aria-label="Move out, Team" autofocus'],
            ['out', $b, 'Team has been moved out, after the entry it was under.', 'id="item-' . $b->id() . '-out"'],
            ['up', $c, 'Hours has been moved up.', 'id="item-' . $c->id() . '-up" class="button button--secondary" aria-label="Move up, Hours" autofocus'],
            ['down', $a, 'About has been moved down.', 'id="item-' . $a->id() . '-down" class="button button--secondary" aria-label="Move down, About" autofocus'],
            ['up', $c, 'Hours: This entry is already the first at its level.', 'aria-label="Move down, Hours" autofocus'],
            ['remove', $d, 'The entry has been removed from the menu.', '<h2 id="menu-main" tabindex="-1" autofocus>'],
        ] as [$action, $item, $message, $focus]) {
            $response = $this->handle('POST', ['action' => $action, 'item' => (string) $item->id()]);
            self::assertSame(303, $response->getStatusCode());
            self::assertSame([$message], Flash::take($this->session), $action);
            if ($action !== 'out') {
                self::assertStringContainsString($focus, (string) $this->handle('GET')->getBody(), $action);
            } else {
                $this->handle('GET');
            }
        }

        $this->handle('POST', ['action' => 'remove', 'item' => 'x']);
        self::assertSame(['That menu entry isn\'t there any more.'], Flash::take($this->session));
    }

    /**
     * @param array<string, string> $body
     */
    private function handle(string $method, array $body = [], ?User $user = null): ResponseInterface
    {
        $handler = new MenuAdminHandler(
            $this->fixture->shell(),
            $this->menus,
            new PageLinkQuery($this->fixture->auth->database->entityManager, $this->pages->addresses()),
            $this->fixture->auth->validator(),
            $this->pages->site,
        );

        return $handler->handle($this->fixture->request($user ?? $this->editor, $method, '/admin/menus', $body, $this->session));
    }
}
