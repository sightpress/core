<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Page\Admin\Http\RedirectsHandler;
use SightPress\Core\Tests\Support\PageAdminFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;

#[CoversClass(RedirectsHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class RedirectsHandlerTest extends TestCase
{
    private PageAdminFixture $fixture;

    private User $editor;

    protected function setUp(): void
    {
        $this->fixture = new PageAdminFixture();
        $this->editor = $this->fixture->admin->auth->user('bea@example.org', 'editor');
        $this->fixture->pages->published('News');
    }

    public function testListsTheRedirectsAndAddsOne(): void
    {
        $html = (string) $this->send('GET')->getBody();
        self::assertStringContainsString('<h1>Redirects</h1>', $html);
        self::assertStringContainsString('There are no redirects yet.', $html);

        $response = $this->send('POST', ['from' => 'https://demo.example/old-news/', 'to' => '/news']);
        self::assertSame([303, RedirectsHandler::PATH], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
        self::assertSame(['Added the redirect: /old-news now leads to /news.'], $this->flash());
        $this->send('POST', ['from' => '/pt-pt/antigo', 'to' => '/pt-pt/novo']);
        $this->flash();
        $this->fixture->pages->redirects()->gone('en-GB', 'gone');

        $html = (string) $this->send('GET')->getBody();
        self::assertStringContainsString('<h2 id="redirects-title">3 redirects</h2>', $html);
        self::assertStringContainsString('<th scope="row"><code>/old-news</code></th>', $html);
        self::assertStringContainsString('<a href="/news"><code>/news</code></a>', $html);
        self::assertStringContainsString('<a href="/pt-pt/novo"><code>/pt-pt/novo</code></a>', $html);
        self::assertStringContainsString('Gone: the page was deleted', $html);
        self::assertStringContainsString('>Remove the redirect from /old-news</button>', $html);
        self::assertStringContainsString('<th scope="col">Language</th>', $html);
    }

    public function testExplainsRedirectsThatCantBeAdded(): void
    {
        foreach ([
            [['from' => '', 'to' => ''], 'Enter the old address'],
            [['from' => '/', 'to' => '/news'], 'The home page can’t be redirected. Enter the address of another page.'],
            [['from' => '/old', 'to' => '/pt-pt/novo'], 'The new address must be in the same language as the old one (British English).'],
            [['from' => '/news', 'to' => '/elsewhere'], 'The page "News" uses the address /news. Only addresses no page uses can be redirected.'],
            [['from' => '/old', 'to' => '/old'], 'That redirect would go round in a loop: the new address leads back to the old one.'],
            [['from' => 'http://', 'to' => '/x'], 'The home page can’t be redirected. Enter the address of another page.'],
        ] as [$body, $message]) {
            $response = $this->send('POST', $body);
            self::assertSame(422, $response->getStatusCode(), $message);
            self::assertStringContainsString(htmlspecialchars($message, ENT_QUOTES), (string) $response->getBody());
        }
    }

    public function testRemovesARedirect(): void
    {
        $redirect = $this->fixture->pages->redirects()->add('en-GB', 'old-news', 'news');

        $response = $this->send('POST', ['op' => 'remove', 'id' => (string) $redirect->id()]);

        self::assertSame(RedirectsHandler::PATH, $response->getHeaderLine('Location'));
        self::assertSame(['Removed the redirect from /old-news. That address now answers “not found”.'], $this->flash());
        self::assertSame([], $this->fixture->pages->redirects()->all());
        $this->expectException(HttpBadRequestException::class);
        $this->send('POST', ['op' => 'remove', 'id' => (string) $redirect->id()]);
    }

    public function testOnlyPeopleWhoEditAnyPageManageRedirects(): void
    {
        $this->expectException(HttpForbiddenException::class);
        $this->fixture->handle($this->fixture->redirectsHandler(), $this->fixture->admin->auth->user('cleo@example.org', 'contributor'), 'GET', RedirectsHandler::PATH);
    }

    /**
     * @param array<string, string> $body
     */
    private function send(string $method, array $body = []): ResponseInterface
    {
        return $this->fixture->handle($this->fixture->redirectsHandler(), $this->editor, $method, RedirectsHandler::PATH, $body);
    }

    /**
     * @return list<string>
     */
    private function flash(): array
    {
        return array_map(strval(...), Flash::take($this->fixture->session));
    }
}
