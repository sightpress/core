<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Admin;

use Aws\S3\S3Client;
use Monolog\Level;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Http\Upload\DirectUploadCheck;
use SightPress\Core\Infrastructure\Storage\SiteFilesystems;
use SightPress\Core\Media\Admin\Http\DirectUploadHandler;
use SightPress\Core\Media\Admin\Http\MediaLibraryHandler;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Media\Upload\BucketUploads;
use SightPress\Core\Media\Upload\ChunkedUploads;
use SightPress\Core\Media\Upload\DirectUploads;
use SightPress\Core\Media\Upload\UploadFolder;
use SightPress\Core\Media\Upload\UploadSessions;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tenant\Environment;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteS3Storage;
use SightPress\Core\Tenant\SiteStorage;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\FakeS3;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\Tests\Support\ServerLimitsFixture;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(DirectUploadHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class DirectUploadHandlerTest extends TestCase
{
    private const string CSV = "name,points\nAda,10\nGrace,12\n";

    private AdminFixture $admin;

    private MediaFixture $media;

    private FakeS3 $s3;

    private Session $session;

    private User $cleo;

    private Site $site;

    private bool $cors = true;

    protected function setUp(): void
    {
        $this->admin = new AdminFixture();
        $this->media = new MediaFixture(new MediaLimits(documentBytes: 4096));
        $this->s3 = new FakeS3();
        $this->session = new Session();
        $this->cleo = $this->admin->auth->user('cleo@example.org', 'contributor');
        $box = SiteFixture::secretBox();
        $this->site = SiteFixture::site()->withStorage(new SiteStorage()->withS3(new SiteS3Storage('media-bucket', 'eu-west-2', 'http://minio:9000', 'sites/one', true, $box->encrypt('id'), $box->encrypt('secret')))->switchedToS3());
    }

    protected function tearDown(): void
    {
        $this->media->remove();
    }

    public function testAWholeUpload(): void
    {
        $id = $this->start();

        $urls = $this->json($this->send('POST', "/admin/media/direct/{$id}/urls", ['parts' => [1]]));
        self::assertIsArray($urls['urls']);
        self::assertIsString($urls['urls']['1']);
        self::assertStringContainsString('partNumber=1', $urls['urls']['1']);

        $this->store($id, self::CSV);
        $status = $this->json($this->send('GET', "/admin/media/direct/{$id}"));
        self::assertIsArray($status['received']);
        self::assertSame(['receiving', 8388608, 1, 20, md5(self::CSV)], [$status['state'], $status['partBytes'], $status['parts'], $status['batch'], $status['received']['1']]);

        $complete = $this->send('POST', "/admin/media/direct/{$id}/complete", ['parts' => [1 => md5(self::CSV), 'x' => 'ignored']]);
        self::assertSame(202, $complete->getStatusCode());
        self::assertSame('validating', $this->json($this->send('GET', "/admin/media/direct/{$id}"))['state']);

        $this->uploads()->validate($id);
        $done = $this->json($this->send('GET', "/admin/media/direct/{$id}"));
        self::assertSame('done', $done['state']);
        self::assertIsString($done['location']);
        self::assertMatchesRegularExpression('#^/admin/media/\d+$#', $done['location']);
        self::assertSame('points.csv has been uploaded. Describe it below, so everyone can understand it.', (string) Flash::take($this->session)[0]);
    }

    public function testARefusedFileIsExplainedInTheLibrary(): void
    {
        $id = $this->start();
        $html = '<!doctype html><html><script>alert(1)</script></html>';
        $this->store($id, $html);
        $this->send('POST', "/admin/media/direct/{$id}/complete", ['parts' => [1 => md5($html)]]);
        $this->uploads()->validate($id);

        $answer = $this->json($this->send('GET', "/admin/media/direct/{$id}"));

        self::assertSame(['refused', '/admin/media'], [$answer['state'], $answer['location']]);
        self::assertNotNull($this->session->get(MediaLibraryHandler::ERROR_KEY));
        $this->expectException(HttpNotFoundException::class);
        $this->send('GET', "/admin/media/direct/{$id}");
    }

    public function testASiteThatCantTakeDirectUploadsSaysSo(): void
    {
        $this->cors = false;

        self::assertSame(['fallback' => true], $this->json($this->send('POST', DirectUploadHandler::PATH, ['name' => 'points.csv', 'type' => 'text/csv', 'size' => 10])));
    }

    public function testARefusalBeforeTheFirstPartGoesToTheLibrary(): void
    {
        $response = $this->send('POST', DirectUploadHandler::PATH, ['name' => 'big.csv', 'type' => 'text/csv', 'size' => 4097]);

        self::assertSame([413, ['location' => '/admin/media']], [$response->getStatusCode(), $this->json($response)]);
        self::assertNotNull($this->session->get(MediaLibraryHandler::ERROR_KEY));
    }

    public function testBadRequests(): void
    {
        self::assertSame(400, $this->send('POST', DirectUploadHandler::PATH, ['name' => 'a.csv'])->getStatusCode());
        self::assertSame(400, $this->sendRaw('POST', DirectUploadHandler::PATH, 'not json')->getStatusCode());
        self::assertSame(400, $this->sendRaw('POST', DirectUploadHandler::PATH, '"text"')->getStatusCode());
        self::assertSame(400, $this->sendRaw('POST', DirectUploadHandler::PATH, str_repeat(' ', 1024 * 1024 + 1))->getStatusCode());
        $id = $this->start();
        self::assertSame(400, $this->send('POST', "/admin/media/direct/{$id}/urls", ['parts' => 'all'])->getStatusCode());
        self::assertSame(400, $this->send('POST', "/admin/media/direct/{$id}/urls", ['parts' => ['one']])->getStatusCode());
        self::assertSame(400, $this->send('POST', "/admin/media/direct/{$id}/complete", ['parts' => 'all'])->getStatusCode());
    }

    public function testABodyLongerThanOneReadIsReadWhole(): void
    {
        $id = $this->start();
        $this->store($id, self::CSV);
        // 640 parts is a 5 GB file; PHP's input gives at most 8 KB per read.
        $parts = array_fill(2, 640, str_repeat('f', 32));
        $parts[1] = md5(self::CSV);
        $inner = Stream::create((string) json_encode(['parts' => $parts], JSON_THROW_ON_ERROR));
        $body = self::createStub(StreamInterface::class);
        $body->method('eof')->willReturnCallback(static fn (): bool => $inner->eof());
        $body->method('read')->willReturnCallback(static fn (int $length): string => $inner->read(min($length, 8192)));
        $request = $this->admin->request($this->cleo, 'POST', "/admin/media/direct/{$id}/complete", [], $this->session)
            ->withBody($body)
            ->withAttribute(DirectUploadHandler::ID_ATTRIBUTE, $id);

        self::assertSame(202, $this->handler()->handle($request)->getStatusCode());
    }

    public function testMissingPartsCanBeRetried(): void
    {
        $id = $this->start();

        $response = $this->send('POST', "/admin/media/direct/{$id}/complete", ['parts' => [1 => 'x']]);

        self::assertSame([409, ['retry' => true]], [$response->getStatusCode(), $this->json($response)]);
    }

    public function testAStorageProblemCanBeRetried(): void
    {
        $id = $this->start();
        $this->s3->failing['ListParts'] = 'InternalError';

        $response = $this->send('GET', "/admin/media/direct/{$id}");

        self::assertSame([503, ['retry' => true]], [$response->getStatusCode(), $this->json($response)]);
    }

    public function testCancelEndsTheUpload(): void
    {
        $id = $this->start();

        self::assertSame(204, $this->send('DELETE', "/admin/media/direct/{$id}")->getStatusCode());
        self::assertSame([], $this->s3->uploads);
    }

    public function testSomeoneElsesUploadIsNotFound(): void
    {
        $id = $this->start();
        $ada = $this->admin->auth->user('ada@example.org', 'admin');

        $this->expectException(HttpNotFoundException::class);
        $this->send('GET', "/admin/media/direct/{$id}", actor: $ada);
    }

    public function testPeopleWhoCantUploadAreRefused(): void
    {
        $member = $this->admin->auth->user('mel@example.org', 'member');

        $this->expectException(HttpForbiddenException::class);
        $this->send('POST', DirectUploadHandler::PATH, ['name' => 'a.csv', 'size' => 1], $member);
    }

    public function testAnActorWithoutAnAccountIsRefused(): void
    {
        $request = $this->admin->request(null, 'POST', DirectUploadHandler::PATH, [], $this->session)->withAttribute(Actor::class, new Actor(null, ['media.upload']));

        $this->expectException(HttpForbiddenException::class);
        $this->handler()->handle($request);
    }

    private function start(): string
    {
        $response = $this->send('POST', DirectUploadHandler::PATH, ['name' => 'points.csv', 'type' => 'text/csv', 'size' => strlen(self::CSV)]);
        self::assertSame(201, $response->getStatusCode());

        $id = $this->json($response)['id'];
        self::assertIsString($id);

        return $id;
    }

    private function store(string $id, string $body): void
    {
        $session = new UploadSessions($this->media->database->connection)->find($id);
        self::assertNotNull($session);
        // The announced size must match what is stored.
        $this->media->database->connection->update('media_upload', ['total_bytes' => strlen($body)], ['id' => $id]);
        $this->s3->uploads[(string) $session->remoteId]['parts'][1] = $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function send(string $method, string $path, array $body = [], ?User $actor = null): ResponseInterface
    {
        return $this->sendRaw($method, $path, $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR), $actor);
    }

    private function sendRaw(string $method, string $path, string $body, ?User $actor = null): ResponseInterface
    {
        $request = $this->admin->request($actor ?? $this->cleo, $method, $path, [], $this->session)->withBody(Stream::create($body));
        if (preg_match('#/direct/([0-9a-f]{32})#', $path, $id) === 1) {
            $request = $request->withAttribute(DirectUploadHandler::ID_ATTRIBUTE, $id[1]);
        }

        return $this->handler()->handle($request);
    }

    private function handler(): DirectUploadHandler
    {
        return new DirectUploadHandler($this->admin->shell(), $this->uploads(), $this->admin->auth->http);
    }

    private function uploads(): DirectUploads
    {
        $config = new TenantConfig('/srv', Environment::Test, false, '/srv/r', '/srv/s', '/srv/c', '/srv/l', Level::Error, uploadTempPath: rtrim($this->media->directory->path, '/') . '/parts');
        $sessions = new UploadSessions($this->media->database->connection);
        $filesystems = new SiteFilesystems(SiteFixture::secretBox(), '/tmp', fn (array $options): S3Client => new S3Client([...$options, 'http_handler' => $this->s3]));
        $headers = $this->cors ? ['Access-Control-Allow-Origin' => 'https://demo.example', 'Access-Control-Allow-Methods' => 'PUT'] : [];
        $check = new DirectUploadCheck($this->site, new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 204, 'response_headers' => $headers])), new ArrayAdapter());
        $chunked = new ChunkedUploads($sessions, new UploadFolder($config, $this->site), new MediaTypes(), $this->media->limits, $this->media->uploader(), ServerLimitsFixture::standard(), $config, $this->media->clock);

        return new DirectUploads($sessions, new BucketUploads($this->site, $filesystems), $check, $chunked, $this->media->uploader(), $this->media->clock);
    }
}
