<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Clock\FrozenClock;
use SightPress\Core\Media\MediaUrlSigner;

#[CoversClass(MediaUrlSigner::class)]
#[UsesClass(FrozenClock::class)]
final class MediaUrlSignerTest extends TestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef';

    private static function signer(string $at = '2026-11-23T12:00:00Z', ?string $secret = 'a-long-test-secret-value-0123456789', string $site = 'site-one'): MediaUrlSigner
    {
        return new MediaUrlSigner($secret, $site, new FrozenClock(new DateTimeImmutable($at)));
    }

    /**
     * @return array<string, string>
     */
    private static function params(string $query): array
    {
        parse_str($query, $params);

        /** @var array<string, string> $params */
        return $params;
    }

    public function testASignatureVerifiesForItsFileAndWidthOnly(): void
    {
        $params = self::params(self::signer()->query(self::KEY, 640, MediaUrlSigner::MEMBER_TTL));

        self::assertTrue(self::signer()->verifies(self::KEY, 640, $params));
        self::assertFalse(self::signer()->verifies(self::KEY, 320, $params));
        self::assertFalse(self::signer()->verifies(self::KEY, null, $params));
        self::assertFalse(self::signer()->verifies(strrev(self::KEY), 640, $params));
    }

    public function testASignatureCannotMoveToAnotherSiteOrSecret(): void
    {
        $params = self::params(self::signer()->query(self::KEY, null, MediaUrlSigner::PREVIEW_TTL));

        self::assertFalse(self::signer(site: 'site-two')->verifies(self::KEY, null, $params));
        self::assertFalse(self::signer(secret: 'another-long-secret-value-012345678')->verifies(self::KEY, null, $params));
    }

    public function testExpiresAndRefusesAChangedOrFarFutureExpiry(): void
    {
        $params = self::params(self::signer()->query(self::KEY, null, MediaUrlSigner::PREVIEW_TTL));

        self::assertTrue(self::signer('2026-11-23T12:14:59Z')->verifies(self::KEY, null, $params));
        self::assertFalse(self::signer('2026-11-23T12:15:00Z')->verifies(self::KEY, null, $params));
        self::assertFalse(self::signer()->verifies(self::KEY, null, [...$params, MediaUrlSigner::EXPIRES => (string) ((int) $params[MediaUrlSigner::EXPIRES] + 900)]));

        $far = self::params(self::signer('2026-11-23T18:00:00Z')->query(self::KEY, null, MediaUrlSigner::MEMBER_TTL));
        self::assertFalse(self::signer()->verifies(self::KEY, null, $far));
    }

    public function testExpiryIsRoundedSoAPageKeepsItsAddresses(): void
    {
        self::assertSame(
            self::signer('2026-11-23T12:01:00Z')->query(self::KEY, null, MediaUrlSigner::MEMBER_TTL),
            self::signer('2026-11-23T12:14:00Z')->query(self::KEY, null, MediaUrlSigner::MEMBER_TTL),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformed(): iterable
    {
        yield 'nothing' => [[]];
        yield 'no signature' => [[MediaUrlSigner::EXPIRES => '1795435200']];
        yield 'array expiry' => [[MediaUrlSigner::EXPIRES => ['1'], MediaUrlSigner::SIGNATURE => 'x']];
        yield 'array signature' => [[MediaUrlSigner::EXPIRES => '1795435200', MediaUrlSigner::SIGNATURE => ['x']]];
        yield 'not a number' => [[MediaUrlSigner::EXPIRES => 'soon', MediaUrlSigner::SIGNATURE => 'x']];
        yield 'wrong signature' => [[MediaUrlSigner::EXPIRES => '1795435200', MediaUrlSigner::SIGNATURE => 'forged']];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('malformed')]
    public function testRefusesMalformedQueries(array $query): void
    {
        self::assertFalse(self::signer()->verifies(self::KEY, null, $query));
    }

    public function testWithoutASecretNothingVerifies(): void
    {
        $params = self::params(self::signer()->query(self::KEY, null, MediaUrlSigner::PREVIEW_TTL));
        $none = self::signer(secret: null);

        self::assertFalse($none->isAvailable());
        self::assertTrue(self::signer()->isAvailable());
        self::assertFalse($none->verifies(self::KEY, null, $params));
    }
}
