<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\User\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\User\Http\AvatarFileHandler;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(AvatarFileHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class AvatarFileHandlerTest extends TestCase
{
    private const string SVG = '0123456789abcdef0123456789abcdef.svg';

    private AuthFixture $auth;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->auth->storage()->write('avatars/' . self::SVG, '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->auth->storage()->write('avatars/0123456789abcdef0123456789abcdef-64.png', 'png bytes');
    }

    public function testSendsAGeneratedAvatarWithAPolicyThatRunsNothing(): void
    {
        $response = $this->handle('GET', self::SVG);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/svg+xml', $response->getHeaderLine('Content-Type'));
        self::assertSame("default-src 'none'; style-src 'unsafe-inline'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('public, max-age=31536000, immutable', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('<svg xmlns="http://www.w3.org/2000/svg"/>', (string) $response->getBody());
    }

    public function testSendsAnUploadedPictureAndAnswersHeadWithoutABody(): void
    {
        $response = $this->handle('HEAD', '0123456789abcdef0123456789abcdef-64.png');

        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertSame('9', $response->getHeaderLine('Content-Length'));
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testOtherNamesAndMissingFilesAreNotFound(): void
    {
        foreach (['../secret.svg', '0123456789abcdef0123456789abcdef.png', '0123456789abcdef0123456789abcdef-100.png', 'ffffffffffffffffffffffffffffffff.svg', null] as $file) {
            try {
                $this->handle('GET', $file);
                self::fail('Found ' . var_export($file, true));
            } catch (HttpNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function handle(string $method, ?string $file): \Psr\Http\Message\ResponseInterface
    {
        $request = new ServerRequest($method, '/avatars/' . $file)->withAttribute(AvatarFileHandler::FILE_ATTRIBUTE, $file);

        return new AvatarFileHandler($this->auth->storage(), $this->auth->http, $this->auth->http)->handle($request);
    }
}
