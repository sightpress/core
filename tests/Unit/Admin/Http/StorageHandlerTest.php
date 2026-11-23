<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Admin\Http\StorageHandler;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteS3Storage;
use SightPress\Core\Tenant\SiteStorage;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\SiteFixture;
use Slim\Exception\HttpForbiddenException;

#[CoversClass(StorageHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class StorageHandlerTest extends TestCase
{
    private AdminFixture $admin;

    protected function setUp(): void
    {
        $this->admin = new AdminFixture();
    }

    private function page(Site $site): string
    {
        $request = $this->admin->request($this->admin->auth->user('ada@example.org', 'admin'), 'GET', StorageHandler::PATH);

        return (string) new StorageHandler($this->admin->shell(), $site)->handle($request)->getBody();
    }

    private static function bucket(?string $endpoint): SiteS3Storage
    {
        $box = SiteFixture::secretBox();

        return new SiteS3Storage('media-bucket', 'eu-west-2', $endpoint, 'sites/one', false, $box->encrypt('AKIAVISIBLE'), $box->encrypt('secret-visible'));
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
        new StorageHandler($this->admin->shell(), SiteFixture::site())->handle($request);
    }
}
