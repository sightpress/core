<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Http\OutboundHosts;
use SightPress\Core\Infrastructure\Http\OutboundHttp;
use SightPress\Core\Infrastructure\Http\OutboundRequestRefused;
use SightPress\Core\Tests\Support\ArrayContainer;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(OutboundHttp::class)]
#[CoversClass(OutboundHosts::class)]
#[CoversClass(OutboundRequestRefused::class)]
final class OutboundHttpTest extends TestCase
{
    public function testSendsToAnAllowedHostWithoutFollowingRedirects(): void
    {
        $seen = [];
        $inner = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [$method, $url, $options['max_redirects']];

            return new MockResponse('ok');
        });
        $http = new OutboundHttp($inner, OutboundHosts::of(['api.example.org', '*.cdn.example.net']));

        self::assertSame('ok', $http->request('GET', 'https://API.example.org/v1')->getContent());
        self::assertSame('ok', $http->request('GET', 'https://eu.cdn.example.net/x', ['max_redirects' => 5])->getContent());
        self::assertSame('ok', $http->withOptions(['base_uri' => 'https://api.example.org'])->request('GET', '/v1/models')->getContent());
        self::assertSame([['GET', 'https://api.example.org/v1', 0], ['GET', 'https://eu.cdn.example.net/x', 0], ['GET', 'https://api.example.org/v1/models', 0]], $seen);
        self::assertSame(['*.cdn.example.net', 'api.example.org'], $http->hosts()->patterns());
        foreach ($http->stream([]) as $chunk) {
            self::fail('Nothing was asked for.');
        }
    }

    public function testRefusesEveryOtherHostBeforeSendingAnything(): void
    {
        $inner = new MockHttpClient(static fn (): never => self::fail('Nothing may be sent.'));
        $http = new OutboundHttp($inner, OutboundHosts::of(['*.example.net']));

        foreach (['https://example.net/', 'https://evil.org/', 'https://example.net.evil.org/', '/relative', 'https://[::1]/'] as $url) {
            try {
                $http->request('POST', $url, ['base_uri' => 42]);
                self::fail($url . ' was sent.');
            } catch (OutboundRequestRefused $refused) {
                self::assertStringContainsString('no service the site uses declares this address', $refused->getMessage());
            }
        }
        $refused = new OutboundRequestRefused('');
        self::assertStringStartsWith('The request to an address without a host was refused', $refused->getMessage());
        self::assertSame('', $refused->host);
    }

    public function testHostsAreReadEveryTime(): void
    {
        $hosts = ['one.example.org'];
        $allowed = new OutboundHosts(static function () use (&$hosts): array {
            return $hosts;
        });

        self::assertTrue($allowed->allows('one.example.org.'));
        $hosts = ['two.example.org', 'TWO.example.org'];
        self::assertFalse($allowed->allows('one.example.org'));
        self::assertFalse($allowed->allows(''));
        self::assertSame(['two.example.org'], $allowed->patterns());
    }

    public function testTheInnerClientComesFromTheContainer(): void
    {
        $inner = new MockHttpClient();

        self::assertSame($inner, OutboundHttp::inner(new ArrayContainer([OutboundHttp::INNER => $inner])));
        $this->expectExceptionMessage('Service sightpress.http.inner is not an HTTP client.');
        OutboundHttp::inner(new ArrayContainer([OutboundHttp::INNER => new \stdClass()]));
    }
}
