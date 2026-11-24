<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use SightPress\Core\Admin\Http\StorageHandler;
use SightPress\Core\Http\Upload\DirectUploadCheck;
use SightPress\Core\Infrastructure\Storage\Provisioning\StorageProvisioning;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteS3Storage;
use SightPress\Core\Tenant\SiteStorage;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\FakeAws;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\Tests\Support\SiteFixture;
use Slim\Exception\HttpForbiddenException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(StorageHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class StorageHandlerTest extends TestCase
{
    private const array ALLOWED = [
        StorageProvisioning::ADMIN_SWITCH => '1',
        'SIGHTPRESS_AWS_ACCESS_KEY_ID' => 'AKIAOPERATOR',
        'SIGHTPRESS_AWS_SECRET_ACCESS_KEY' => 'operator-secret',
    ];

    private AdminFixture $admin;

    private FakeAws $aws;

    private RecordingEvents $events;

    /** @var array<string, string> */
    private array $corsHeaders = [];

    private bool $unreachable = false;

    protected function setUp(): void
    {
        $this->admin = new AdminFixture();
        $this->aws = new FakeAws();
        $this->events = new RecordingEvents();
    }

    /**
     * @param array<string, string> $environment
     */
    private function handler(Site $site, array $environment = []): StorageHandler
    {
        $provisioning = new StorageProvisioning(
            TenantConfig::fromArray([], '/tmp/project', ['APP_ENV' => 'production']),
            new MockHttpClient(),
            static fn (string $name): string|false => $environment[$name] ?? false,
            ['handler' => $this->aws],
        );

        $preflight = new MockResponse('', ['http_code' => 204, 'response_headers' => $this->corsHeaders]);

        return new StorageHandler($this->admin->shell(), $site, $provisioning, $this->events, new NullLogger(), new DirectUploadCheck($site, new MockHttpClient($this->unreachable ? static fn () => throw new TransportException('down') : $preflight), new ArrayAdapter()));
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $environment
     */
    private function post(array $body, array $environment = self::ALLOWED): ResponseInterface
    {
        $request = $this->admin->request($this->admin->auth->user('post-' . bin2hex(random_bytes(4)) . '@example.org', 'admin'), 'POST', StorageHandler::PATH, $body);

        return $this->handler(SiteFixture::site(), $environment)->handle($request);
    }

    private function page(Site $site): string
    {
        $request = $this->admin->request($this->admin->auth->user('page-' . bin2hex(random_bytes(4)) . '@example.org', 'admin'), 'GET', StorageHandler::PATH);

        return (string) $this->handler($site)->handle($request)->getBody();
    }

    private static function bucket(?string $endpoint): SiteS3Storage
    {
        $box = SiteFixture::secretBox();

        return new SiteS3Storage('media-bucket', 'eu-west-2', $endpoint, 'sites/one', false, $box->encrypt('AKIAVISIBLE'), $box->encrypt('secret-visible'));
    }

    public function testSaysHowLargeFilesTravelAndWhy(): void
    {
        $site = SiteFixture::site();
        $cloud = $site->withStorage(new SiteStorage()->withS3(self::bucket('https://files.example'))->switchedToS3());
        self::assertStringContainsString('because the site doesn’t keep its files in a bucket', $this->page($site));
        self::assertStringContainsString('(its CORS rules)', $this->page($cloud));

        $this->corsHeaders = ['Access-Control-Allow-Origin' => 'https://demo.example', 'Access-Control-Allow-Methods' => 'PUT'];
        self::assertStringContainsString('Large files go straight from the browser to the bucket.', $this->page($cloud));

        $this->unreachable = true;
        self::assertStringContainsString('the bucket can’t be reached', $this->page($cloud));
    }

    public function testLocalSite(): void
    {
        $page = $this->page(SiteFixture::site());
        self::assertStringContainsString('This site keeps its files on this server.', $page);
        self::assertStringNotContainsString('<dl>', $page);
    }

    public function testConnectedButNotMoved(): void
    {
        $site = SiteFixture::site();
        $page = $this->page($site->withStorage(new SiteStorage()->withS3(self::bucket('https://files.example'))));
        self::assertStringContainsString('haven’t been moved', $page);
        self::assertStringContainsString('files.example', $page);
    }

    public function testCloudSiteNeverShowsAKey(): void
    {
        $site = SiteFixture::site();
        $s3 = self::bucket(null);
        $page = $this->page($site->withStorage(new SiteStorage()->withS3($s3)->switchedToS3()));
        self::assertStringContainsString('in a cloud storage bucket', $page);
        self::assertStringContainsString('Amazon S3', $page);
        self::assertStringContainsString('media-bucket', $page);
        foreach (['AKIAVISIBLE', 'secret-visible', $s3->accessKey, $s3->secretKey] as $secret) {
            self::assertStringNotContainsString($secret, $page);
        }
    }

    public function testNamesTheCdn(): void
    {
        $site = SiteFixture::site();
        self::assertStringContainsString('https://cdn.example', $this->page($site->withStorage($site->storage->withCdn('https://cdn.example'))));
    }

    public function testNeedsThePermission(): void
    {
        $request = $this->admin->request($this->admin->auth->user('cleo@example.org', 'contributor'), 'GET', StorageHandler::PATH);
        $this->expectException(HttpForbiddenException::class);
        $this->handler(SiteFixture::site())->handle($request);
    }

    public function testNoSetUpFormUnlessTheServerAllowsIt(): void
    {
        $request = $this->admin->request($this->admin->auth->user('page-' . bin2hex(random_bytes(4)) . '@example.org', 'admin'), 'GET', StorageHandler::PATH);
        self::assertStringNotContainsString('name="provider"', (string) $this->handler(SiteFixture::site(), [StorageProvisioning::ADMIN_SWITCH => '0'] + self::ALLOWED)->handle($request)->getBody());
        self::assertStringNotContainsString('name="provider"', (string) $this->handler(SiteFixture::site(), [StorageProvisioning::ADMIN_SWITCH => '1'])->handle($request)->getBody());
        $form = (string) $this->handler(SiteFixture::site(), self::ALLOWED)->handle($request)->getBody();
        self::assertStringContainsString('value="aws"', $form);
        self::assertStringNotContainsString('value="cloudflare"', $form);

        $this->expectException(HttpForbiddenException::class);
        $this->post(['provider' => 'aws', 'confirm' => '1'], []);
    }

    public function testChoosingAProviderShowsThePlanAndChangesNothing(): void
    {
        $response = $this->post(['provider' => 'aws']);
        $page = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('a third party', $page);
        self::assertStringContainsString('eu-west-2', $page);
        self::assertStringContainsString('name="confirm"', $page);
        self::assertStringNotContainsString('AKIAOPERATOR', $page);
        self::assertSame([], $this->aws->calls);
        self::assertSame([], $this->events->events);
    }

    public function testConfirmingSetsItUpAndIsAudited(): void
    {
        $this->aws->answers = [
            'CreateOriginAccessControl' => ['OriginAccessControl' => ['Id' => 'o']],
            'CreateCachePolicy' => ['CachePolicy' => ['Id' => 'c']],
            'CreateDistribution' => ['Distribution' => ['ARN' => 'arn:aws:cloudfront::1:distribution/D', 'DomainName' => 'd.cloudfront.net']],
        ];
        self::assertSame(303, $this->post(['provider' => 'aws', 'confirm' => '1'])->getStatusCode());
        self::assertContains('CreateDistribution', $this->aws->names());
        self::assertInstanceOf(SettingsChanged::class, $this->events->events[0]);
        self::assertSame([StorageProvisioning::SETTING], $this->events->events[0]->keys);
    }

    public function testAProblemIsSaidPlainly(): void
    {
        $this->aws->answers = ['HeadBucket' => ['error' => 'InvalidAccessKeyId', 'status' => 403]];
        $response = $this->post(['provider' => 'aws', 'confirm' => '1']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('role="alert"', (string) $response->getBody());
        self::assertStringNotContainsString('AKIA', (string) $response->getBody());
        self::assertSame([], $this->events->events);
    }

    public function testAProviderWithoutKeysCantBeChosen(): void
    {
        $response = $this->post(['provider' => 'cloudflare', 'confirm' => '1']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Choose a provider.', (string) $response->getBody());
        self::assertSame([], $this->aws->calls);
    }
}
