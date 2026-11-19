<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Http\EndpointGuard;
use SightPress\Core\Assistant\Http\EndpointRefused;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\Assistant\FixedResolver;

#[CoversClass(EndpointGuard::class)]
#[CoversClass(EndpointRefused::class)]
#[UsesNamespace('SightPress\Core')]
final class EndpointGuardTest extends TestCase
{
    public function testAllowsHttpsToPublicHostsAndPinsTheCheckedAddress(): void
    {
        $guard = self::guard();

        self::assertSame(['ai.example.org' => '93.184.215.14'], $guard->check('https://ai.example.org/v1'));
        self::assertSame(['8.8.8.8' => '8.8.8.8'], self::guard(hosts: ['8.8.8.8' => ['8.8.8.8']])->check('https://8.8.8.8/v1'));
        self::assertNull($guard->problem('https://AI.example.org:8443/v1'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'not a URL' => ['ai.example.org/v1', 'must be a full web address'];
        yield 'another scheme' => ['ftp://ai.example.org/v1', 'must be a full web address'];
        yield 'user name' => ['https://me:secret@ai.example.org/v1', 'must not contain a user name or password'];
        yield 'plain HTTP' => ['http://ai.example.org/v1', 'must start with https://'];
        yield 'unknown host' => ['https://nowhere.example/v1', 'there is no server called nowhere.example'];
        yield 'loopback' => ['https://local.example/v1', 'is on a private or local network'];
        yield 'private network' => ['https://intranet.example/v1', 'is on a private or local network'];
        yield 'link-local metadata' => ['https://metadata.example/v1', 'is on a private or local network'];
        yield 'one private address among public ones' => ['https://mixed.example/v1', 'is on a private or local network'];
        yield 'IPv6 loopback' => ['https://[::1]/v1', 'is on a private or local network'];
        yield 'IPv4-mapped loopback' => ['https://mapped.example/v1', 'is on a private or local network'];
    }

    #[DataProvider('refused')]
    public function testRefusesAddressesItMayNotUse(string $url, string $because): void
    {
        $guard = self::guard(hosts: [
            'local.example' => ['127.0.0.1'],
            'intranet.example' => ['10.1.2.3'],
            'metadata.example' => ['169.254.169.254'],
            'mixed.example' => ['93.184.215.14', '192.168.1.10'],
            '::1' => ['::1'],
            'mapped.example' => ['::ffff:127.0.0.1'],
        ]);

        self::assertStringContainsString($because, (string) $guard->problem($url));
        $this->expectException(EndpointRefused::class);
        $guard->check($url);
    }

    public function testThePlainHttpAndPrivateHostRulesCanBeRelaxed(): void
    {
        self::assertSame(['ai.example.org' => '93.184.215.14'], self::guard('dev')->check('http://ai.example.org:8080/v1'));
        self::assertSame([], self::guard('production', private: true)->check('https://anything.local/v1'));
        self::assertStringContainsString('must start with https://', (string) self::guard('production', private: true)->problem('http://anything.local/v1'));
        self::assertStringContainsString('is on a private', (string) self::guard('dev', hosts: ['local.example' => ['127.0.0.1']])->problem('http://local.example/v1'));
        self::assertSame([], self::guard('dev', private: true)->check('http://localhost:11434/v1'));
    }

    public function testKnowsPublicAddresses(): void
    {
        self::assertTrue(EndpointGuard::isPublic('93.184.215.14'));
        self::assertTrue(EndpointGuard::isPublic('2606:2800:220:1::1'));
        foreach (['10.0.0.1', '172.16.0.1', '192.168.0.1', '127.0.0.1', '169.254.1.1', '100.64.0.1', '0.0.0.0', '::1', 'fe80::1', 'fd00::1', '::ffff:10.0.0.1', 'not an address'] as $address) {
            self::assertFalse(EndpointGuard::isPublic($address), $address);
        }
    }

    /**
     * @param array<string, list<string>>|null $hosts
     */
    private static function guard(string $environment = 'production', ?array $hosts = null, bool $private = false): EndpointGuard
    {
        $config = TenantConfig::fromArray(['assistant' => ['allow_private_hosts' => $private]], '/tmp/project', ['APP_ENV' => $environment]);

        return new EndpointGuard($config, $hosts === null ? new FixedResolver() : new FixedResolver($hosts));
    }
}
