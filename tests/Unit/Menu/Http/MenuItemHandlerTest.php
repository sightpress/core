<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Menu\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Menu\Contract\MenuChanged;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\Entity\MenuItem;
use SightPress\Core\Menu\Http\MenuAdminHandler;
use SightPress\Core\Menu\Http\MenuItemHandler;
use SightPress\Core\Menu\MenuEditor;
use SightPress\Core\Page\PageLinkQuery;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\Tests\Support\Text;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(MenuItemHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class MenuItemHandlerTest extends TestCase
{
    private AdminFixture $fixture;

    private PageFixture $pages;

    private MenuEditor $menus;

    private RecordingEvents $events;

    private User $editor;

    private Session $session;

    protected function setUp(): void
    {
        $this->fixture = new AdminFixture();
        $this->pages = new PageFixture($this->fixture->auth->database);
        $this->events = new RecordingEvents();
        $this->menus = new MenuEditor($this->fixture->auth->database->entityManager, $this->events);
        $this->editor = $this->fixture->auth->user();
        $this->session = new Session();
    }

    public function testOnlyPeopleWhoManageMenusMayUseIt(): void
    {
        $item = $this->menus->add(MenuName::Main, null, 'Hours', '/hours');

        $this->expectException(HttpForbiddenException::class);
        $this->handle($item, 'GET', user: $this->fixture->auth->user('cleo@example.org', 'contributor'));
    }

    public function testAnEntryThatIsntThereIsNotFound(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->handle(null, 'GET');
    }

    public function testAPageEntryHasAnOptionalTextInEachLanguage(): void
    {
        $about = $this->pages->published('About');
        $item = $this->menus->add(MenuName::Main, (int) $about->id());

        $html = (string) $this->handle($item, 'GET')->getBody();
        self::assertStringContainsString('<title>Change the menu entry “About” - Admin - Demo</title>', $html);
        self::assertStringContainsString('This entry leads to a page of the site.', $html);
        self::assertStringContainsString('<label for="menu-item-label">Text in British English (optional)</label>', $html);
        self::assertStringContainsString('<label for="menu-item-label-pt-pt">Text in European Portuguese (optional)</label>', $html);
        self::assertStringContainsString('Leave it empty to show the page’s title in this language.', $html);
        self::assertStringContainsString('<a href="/admin/menus">Back to the menus</a>', $html);

        $saved = $this->handle($item, 'POST', ['label' => '', 'label_pt_pt' => 'Quem somos', 'members_only' => '1']);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/admin/menus', $saved->getHeaderLine('Location'));
        self::assertSame(['About has been changed.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        self::assertSame('item-' . $item->id(), $this->session->get(MenuAdminHandler::FOCUS));
        self::assertSame([null, ['pt-PT' => 'Quem somos'], true], [$item->label(), $item->labels(), $item->isMembersOnly()]);
        self::assertInstanceOf(MenuChanged::class, end($this->events->events));

        $again = (string) $this->handle($item, 'GET')->getBody();
        self::assertStringContainsString('value="Quem somos"', $again);
        self::assertStringContainsString('value="1" checked name="members_only"', $again);
    }

    public function testALinkNeedsItsMainTextAndLongTextsAreRefused(): void
    {
        $item = $this->menus->add(MenuName::Footer, null, 'Hours', '/hours');

        $html = (string) $this->handle($item, 'GET')->getBody();
        self::assertStringContainsString('This entry is a link to /hours.', $html);
        self::assertStringContainsString('<label for="menu-item-label">Text in British English</label>', $html);
        self::assertStringContainsString('Leave it empty to show the text in the main language.', $html);
        self::assertStringContainsString('value="Hours"', $html);

        $empty = $this->handle($item, 'POST', ['label' => '', 'label_pt_pt' => '']);
        self::assertSame(422, $empty->getStatusCode());
        self::assertStringContainsString('Enter text in British English', (string) $empty->getBody());
        $long = $this->handle($item, 'POST', ['label' => str_repeat('x', 101)]);
        self::assertStringContainsString('must be 100 characters or fewer', (string) $long->getBody());
        self::assertSame('Hours', $item->label());
    }

    public function testARefusalOfTheMenuIsShownOnTheText(): void
    {
        $item = $this->menus->add(MenuName::Footer, null, 'Hours', '/hours');

        $response = $this->handle($item, 'POST', ['label' => '   ', 'label_pt_pt' => '']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Hours', $item->label());
    }

    public function testAnEntryForAPageThatIsGoneIsCalledTheEntry(): void
    {
        $item = $this->menus->add(MenuName::Main, 999);

        self::assertStringContainsString('<h1>Change the menu entry “The entry”</h1>', (string) $this->handle($item, 'GET')->getBody());
    }

    /**
     * @param array<string, string> $body
     */
    private function handle(?MenuItem $item, string $method, array $body = [], ?User $user = null): ResponseInterface
    {
        $handler = new MenuItemHandler(
            $this->fixture->shell(),
            $this->menus,
            new PageLinkQuery($this->fixture->auth->database->entityManager, $this->pages->addresses(), $this->fixture->auth->database->settings()),
            $this->fixture->auth->validator(),
            $this->pages->site,
        );
        $request = $this->fixture->request($user ?? $this->editor, $method, '/admin/menus/' . ($item?->id() ?? 'x'), $body, $this->session);

        return $handler->handle($request->withAttribute(MenuItemHandler::ID_ATTRIBUTE, $item === null ? 'x' : (string) $item->id()));
    }
}
