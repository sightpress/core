<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Admin;

use Monolog\Level;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Media\Admin\Http\MediaLibraryHandler;
use SightPress\Core\Media\Admin\Http\TusUploadHandler;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Media\Upload\ChunkedUploads;
use SightPress\Core\Media\Upload\UploadFolder;
use SightPress\Core\Media\Upload\UploadSessions;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tenant\Environment;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\Tests\Support\ServerLimitsFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(TusUploadHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class TusUploadHandlerTest extends TestCase
{
    private const string CSV = "name,points\nAda,10\nGrace,12\n";

    private AdminFixture $admin;

    private MediaFixture $media;

    private Session $session;

    private User $cleo;

    private User $ada;

    protected function setUp(): void
    {
        $this->admin = new AdminFixture();
        $this->media = new MediaFixture(new MediaLimits(documentBytes: 4096));
        $this->session = new Session();
        $this->cleo = $this->admin->auth->user('cleo@example.org', 'contributor');
        $this->ada = $this->admin->auth->user('ada@example.org', 'admin');
    }

    protected function tearDown(): void
    {
        $this->media->remove();
    }

    public function testOptionsDescribesTheServer(): void
    {
        $response = $this->send('OPTIONS', TusUploadHandler::PATH, tus: false);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('1.0.0', $response->getHeaderLine('Tus-Version'));
        self::assertSame('creation,termination,checksum', $response->getHeaderLine('Tus-Extension'));
        self::assertSame('sha256', $response->getHeaderLine('Tus-Checksum-Algorithm'));
        self::assertSame((string) TenantConfig::DEFAULT_MAX_CHUNKED_BYTES, $response->getHeaderLine('Tus-Max-Size'));
    }

    public function testAnotherProtocolVersionIsRefused(): void
    {
        $response = $this->send('POST', TusUploadHandler::PATH, tus: false);

        self::assertSame(412, $response->getStatusCode());
        self::assertSame('1.0.0', $response->getHeaderLine('Tus-Version'));
    }

    public function testAWholeUploadEndsOnThePageOfTheNewFile(): void
    {
        $url = $this->create();

        $head = $this->send('HEAD', $url);
        self::assertSame([200, '0', (string) strlen(self::CSV), 'no-store'], [$head->getStatusCode(), $head->getHeaderLine('Upload-Offset'), $head->getHeaderLine('Upload-Length'), $head->getHeaderLine('Cache-Control')]);

        $part = substr(self::CSV, 0, 10);
        $first = $this->patch($url, 0, $part, ['Upload-Checksum' => 'sha256 ' . base64_encode(hash('sha256', $part, true)), 'Content-Length' => '10']);
        self::assertSame([204, '10', ''], [$first->getStatusCode(), $first->getHeaderLine('Upload-Offset'), $first->getHeaderLine('X-Media-Location')]);

        $last = $this->patch($url, 10, substr(self::CSV, 10));
        self::assertSame(204, $last->getStatusCode());
        self::assertMatchesRegularExpression('#^/admin/media/\d+$#', $last->getHeaderLine('X-Media-Location'));
        self::assertSame('points.csv has been uploaded. Describe it below, so everyone can understand it.', (string) Flash::take($this->session)[0]);

        // The answer to the last part can get lost: asking again says where the file went.
        self::assertSame($last->getHeaderLine('X-Media-Location'), $this->send('HEAD', $url)->getHeaderLine('X-Media-Location'));
    }

    public function testTheSameFileAgainSaysItIsAlreadyThere(): void
    {
        $this->patch($this->create(), 0, self::CSV);
        Flash::take($this->session);

        $this->patch($this->create(), 0, self::CSV);

        self::assertStringContainsString('is already in the library', (string) Flash::take($this->session)[0]);
    }

    public function testBadRequests(): void
    {
        self::assertSame(400, $this->send('POST', TusUploadHandler::PATH, ['Upload-Length' => 'many'])->getStatusCode());
        $url = $this->create();

        self::assertSame(415, $this->send('PATCH', $url, ['Upload-Offset' => '0', 'Content-Type' => 'text/plain'])->getStatusCode());
        self::assertSame(400, $this->patch($url, 'x', 'a')->getStatusCode());
        self::assertSame(400, $this->patch($url, 0, 'a', ['Upload-Checksum' => 'md5 YWJj'])->getStatusCode());
        self::assertSame(400, $this->patch($url, 0, 'a', ['Upload-Checksum' => 'sha256'])->getStatusCode());
        self::assertSame(400, $this->patch($url, 0, 'a', ['Upload-Checksum' => 'sha256 YWJj'])->getStatusCode());
        self::assertSame(400, $this->patch($url, 0, 'a', ['Upload-Checksum' => 'sha256 !!'])->getStatusCode());
    }

    public function testOffsetAndChecksumProblemsCanBeRetried(): void
    {
        $url = $this->create();

        $conflict = $this->patch($url, 3, 'abc');
        self::assertSame([409, ''], [$conflict->getStatusCode(), $conflict->getHeaderLine('X-Media-Location')]);

        $damaged = $this->patch($url, 0, 'abc', ['Upload-Checksum' => 'sha256 ' . base64_encode(str_repeat("\0", 32))]);
        self::assertSame([460, ''], [$damaged->getStatusCode(), $damaged->getHeaderLine('X-Media-Location')]);
    }

    public function testARefusalWaitsInTheLibrarysErrorSummary(): void
    {
        $response = $this->send('POST', TusUploadHandler::PATH, ['Upload-Length' => '4097', 'Upload-Metadata' => 'filename ' . base64_encode('big.csv') . ',filetype ' . base64_encode('text/csv')]);

        self::assertSame([413, '/admin/media'], [$response->getStatusCode(), $response->getHeaderLine('X-Media-Location')]);
        self::assertNotNull($this->session->get(MediaLibraryHandler::ERROR_KEY));
    }

    public function testMetadataThatIsNotBase64IsIgnored(): void
    {
        $response = $this->send('POST', TusUploadHandler::PATH, ['Upload-Length' => '10', 'Upload-Metadata' => 'filename !!!,  ,flag']);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testCancelEndsTheUpload(): void
    {
        $url = $this->create();

        self::assertSame(204, $this->send('DELETE', $url)->getStatusCode());

        $this->expectException(HttpNotFoundException::class);
        $this->send('HEAD', $url);
    }

    public function testSomeoneElsesUploadIsNotFound(): void
    {
        $url = $this->create();

        $this->expectException(HttpNotFoundException::class);
        $this->send('PATCH', $url, ['Upload-Offset' => '0'], actor: $this->ada);
    }

    public function testARequestWithoutASessionIdIsNotFound(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->handler()->handle($this->admin->request($this->cleo, 'HEAD', TusUploadHandler::PATH, [], $this->session)->withHeader('Tus-Resumable', '1.0.0'));
    }

    public function testPeopleWhoCantUploadAreRefused(): void
    {
        $member = $this->admin->auth->user('mel@example.org', 'member');

        $this->expectException(HttpForbiddenException::class);
        $this->send('POST', TusUploadHandler::PATH, actor: $member);
    }

    public function testAnActorWithoutAnAccountIsRefused(): void
    {
        $request = $this->admin->request(null, 'POST', TusUploadHandler::PATH, [], $this->session)->withAttribute(Actor::class, new Actor(null, ['media.upload']));

        $this->expectException(HttpForbiddenException::class);
        $this->handler()->handle($request);
    }

    private function create(): string
    {
        $response = $this->send('POST', TusUploadHandler::PATH, ['Upload-Length' => (string) strlen(self::CSV), 'Upload-Metadata' => 'filename ' . base64_encode('points.csv') . ',filetype ' . base64_encode('text/csv')]);
        self::assertSame([201, '0', '8388608'], [$response->getStatusCode(), $response->getHeaderLine('Upload-Offset'), $response->getHeaderLine('X-Chunk-Bytes')]);

        return $response->getHeaderLine('Location');
    }

    /**
     * @param array<string, string> $headers
     */
    private function patch(string $url, int|string $offset, string $body, array $headers = []): ResponseInterface
    {
        return $this->send('PATCH', $url, ['Upload-Offset' => (string) $offset, 'Content-Type' => 'application/offset+octet-stream'] + $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, array $headers = [], string $body = '', bool $tus = true, ?User $actor = null): ResponseInterface
    {
        $request = $this->admin->request($actor ?? $this->cleo, $method, $path, [], $this->session)->withBody(Stream::create($body));
        if ($tus) {
            $request = $request->withHeader('Tus-Resumable', '1.0.0');
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if (preg_match('#/([0-9a-f]{32})$#', $path, $id) === 1) {
            $request = $request->withAttribute(TusUploadHandler::ID_ATTRIBUTE, $id[1]);
        }

        return $this->handler()->handle($request);
    }

    private function handler(): TusUploadHandler
    {
        $config = new TenantConfig('/srv', Environment::Test, false, '/srv/r', '/srv/s', '/srv/c', '/srv/l', Level::Error, uploadTempPath: $this->media->directory->path . '/parts');

        return new TusUploadHandler(
            $this->admin->shell(),
            new ChunkedUploads(
                new UploadSessions($this->media->database->connection),
                new UploadFolder($config, $this->admin->auth->site),
                new MediaTypes(),
                $this->media->limits,
                $this->media->uploader(),
                ServerLimitsFixture::standard(),
                $config,
                $this->media->clock,
            ),
            $this->admin->auth->http,
        );
    }
}
