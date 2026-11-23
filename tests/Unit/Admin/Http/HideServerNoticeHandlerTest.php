<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Admin\Http\HideServerNoticeHandler;
use SightPress\Core\Http\Upload\ServerNotice;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\ServerLimitsFixture;
use Slim\Exception\HttpForbiddenException;

#[CoversClass(HideServerNoticeHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class HideServerNoticeHandlerTest extends TestCase
{
    private AdminFixture $admin;

    private ServerNotice $notice;

    protected function setUp(): void
    {
        $this->admin = new AdminFixture();
        $database = $this->admin->auth->database;
        $this->notice = ServerLimitsFixture::notice(ServerLimitsFixture::standard(inputVars: 1000), $database->connection, $database->settings(), $this->admin->auth->clock);
    }

    #[DataProvider('referers')]
    public function testHidesTheNoticeAndGoesBackToAnAdminScreen(string $referer, string $expected): void
    {
        $user = $this->admin->auth->user('ada@example.org', 'admin');
        $request = $this->admin->request($user, 'POST', HideServerNoticeHandler::PATH)->withHeader('Referer', $referer);

        $response = new HideServerNoticeHandler($this->admin->shell(), $this->notice)->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame($expected, $response->getHeaderLine('Location'));
        self::assertSame([], $this->notice->visible());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function referers(): iterable
    {
        yield 'the dashboard' => ['https://demo.example/admin', '/admin'];
        yield 'the media library' => ['https://demo.example/admin/media?page=2', '/admin/media?page=2'];
        yield 'a public page' => ['https://demo.example/about', '/admin'];
        yield 'looks like admin' => ['https://demo.example/administrator', '/admin'];
        yield 'another site' => ['https://evil.example/admin', '/admin'];
        yield 'none' => ['', '/admin'];
    }

    public function testNeedsThePermission(): void
    {
        $request = $this->admin->request($this->admin->auth->user('cleo@example.org', 'contributor'), 'POST', HideServerNoticeHandler::PATH);

        $this->expectException(HttpForbiddenException::class);

        new HideServerNoticeHandler($this->admin->shell(), $this->notice)->handle($request);
    }
}
