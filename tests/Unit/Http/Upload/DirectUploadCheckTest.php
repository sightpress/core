<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Upload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Upload\DirectUploadCheck;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteS3Storage;
use SightPress\Core\Tenant\SiteStorage;
use SightPress\Core\Tests\Support\SiteFixture;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(DirectUploadCheck::class)]
#[CoversClass(SiteS3Storage::class)]
#[UsesNamespace('SightPress\Core')]
final class DirectUploadCheckTest extends TestCase
{
    private const string ORIGIN = 'https://demo.example';

    private static function cloud(bool $pathStyle = true, ?string $endpoint = 'http://minio:9000'): Site
    {
        $box = SiteFixture::secretBox();
        $site = SiteFixture::site();

        return $site->withStorage(new SiteStorage()->withS3(new SiteS3Storage('media-bucket', 'eu-west-2', $endpoint, 'sites/one', $pathStyle, $box->encrypt('id'), $box->encrypt('secret')))->switchedToS3());
    }

    public function testASiteWithoutABucketIsLocal(): void
    {
        $site = SiteFixture::site();
        $check = new DirectUploadCheck($site, new MockHttpClient(static fn () => self::fail('No request.')), new ArrayAdapter());
        self::assertSame(DirectUploadCheck::LOCAL, $check->status(self::ORIGIN));

        $box = SiteFixture::secretBox();
        $connected = $site->withStorage(new SiteStorage()->withS3(new SiteS3Storage('media-bucket', 'eu-west-2', null, 'sites/one', false, $box->encrypt('id'), $box->encrypt('secret'))));
        $check = new DirectUploadCheck($connected, new MockHttpClient(static fn () => self::fail('No request.')), new ArrayAdapter());
        self::assertSame(DirectUploadCheck::LOCAL, $check->status(self::ORIGIN), 'connected, but its files are not there yet');
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('answers')]
    public function testAsksTheBucketWhatABrowserWould(int $code, array $headers, string $expected): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent, $code, $headers): MockResponse {
            /** @var array{normalized_headers: array<string, list<string>>} $options */
            $sent = [$method, $url, $options['normalized_headers']['origin'][0] ?? '', $options['normalized_headers']['access-control-request-method'][0] ?? ''];

            return new MockResponse('', ['http_code' => $code, 'response_headers' => $headers]);
        });

        self::assertSame($expected, new DirectUploadCheck(self::cloud(), $http, new ArrayAdapter())->status(self::ORIGIN));
        self::assertSame(['OPTIONS', 'http://minio:9000/media-bucket/sites/one/incoming/check', 'Origin: ' . self::ORIGIN, 'Access-Control-Request-Method: PUT'], $sent);
    }

    /**
     * @return iterable<string, array{int, array<string, string>, string}>
     */
    public static function answers(): iterable
    {
        $good = ['Access-Control-Allow-Methods' => 'GET, PUT'];
        yield 'this origin' => [204, ['Access-Control-Allow-Origin' => self::ORIGIN] + $good, DirectUploadCheck::READY];
        yield 'any origin' => [200, ['Access-Control-Allow-Origin' => '*'] + $good, DirectUploadCheck::READY];
        yield 'another origin' => [204, ['Access-Control-Allow-Origin' => 'https://other.example'] + $good, DirectUploadCheck::NO_CORS];
        yield 'no PUT' => [204, ['Access-Control-Allow-Origin' => self::ORIGIN, 'Access-Control-Allow-Methods' => 'GET'], DirectUploadCheck::NO_CORS];
        yield 'no headers' => [403, [], DirectUploadCheck::NO_CORS];
    }

    public function testAnUnreachableBucketIsSaidSoAndTheAnswerIsKept(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            throw new TransportException('down');
        });
        $check = new DirectUploadCheck(self::cloud(), $http, new ArrayAdapter());

        self::assertSame(DirectUploadCheck::UNREACHABLE, $check->status(self::ORIGIN));
        self::assertSame(DirectUploadCheck::UNREACHABLE, $check->status(self::ORIGIN));
        self::assertSame(1, $calls);
    }

    public function testTheBrowserAddressMatchesWhatTheClientSigns(): void
    {
        $box = SiteFixture::secretBox();
        $make = static fn (string $region, ?string $endpoint, bool $path): SiteS3Storage => new SiteS3Storage('media-bucket', $region, $endpoint, 'sites/one', $path, $box->encrypt('id'), $box->encrypt('secret'));

        self::assertSame('https://media-bucket.s3.eu-west-2.amazonaws.com', $make('eu-west-2', null, false)->uploadOrigin());
        self::assertSame('https://media-bucket.s3.amazonaws.com', $make('us-east-1', null, false)->uploadOrigin());
        self::assertSame('http://minio:9000', $make('us-east-1', 'http://minio:9000/', true)->uploadOrigin());
        self::assertSame('https://media-bucket.files.example', $make('auto', 'https://files.example', false)->uploadOrigin());
    }
}
