<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\Privacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Privacy\Row;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Privacy\CodeData;
use SightPress\Core\Auth\Privacy\RememberTokenData;
use SightPress\Core\Auth\Privacy\ThrottleData;
use SightPress\Core\Auth\RememberTokens;
use SightPress\Core\Auth\VerificationCodeStore;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\Privacy\SiteTables;
use SightPress\Module\Privacy\ErasureRule;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Sms\PhoneNumber;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(RememberTokenData::class)]
#[CoversClass(CodeData::class)]
#[CoversClass(ThrottleData::class)]
#[CoversClass(VerificationCodeStore::class)]
#[UsesNamespace('SightPress\Core')]
final class AuthDataTest extends TestCase
{
    public function testExportsAndDeletesTheDevicesThatKeepThePersonSignedIn(): void
    {
        $connection = SiteTables::connection();
        foreach ([['a', 7, '2026-12-01 10:00:00'], ['b', 7, '2026-12-02 10:00:00'], ['c', 8, '2026-12-03 10:00:00']] as [$selector, $user, $expires]) {
            $connection->insert(RememberTokens::TABLE, ['selector' => str_repeat($selector, 32), 'validator_hash' => 'h', 'user_id' => $user, 'expires_at' => $expires]);
        }
        $data = new RememberTokenData($connection);
        $ada = new UserRef(7, 'ada@example.org');

        self::assertSame(30, $data->describe()->retention->days);
        self::assertSame([RememberTokens::SECURE_COOKIE, RememberTokens::COOKIE], array_map(static fn ($cookie) => $cookie->name, RememberTokenData::cookies()));
        self::assertSame(RememberTokens::LIFETIME, RememberTokenData::cookies()[0]->lifetime);
        self::assertSame([['ends_at' => '2026-12-01T10:00:00Z'], ['ends_at' => '2026-12-02T10:00:00Z']], iterator_to_array($data->export($ada), false));
        self::assertSame(2, $data->erase($ada)->deleted);
        self::assertSame(1, Row::int($connection->fetchOne('SELECT COUNT(*) FROM auth_remember_tokens')));
    }

    public function testFindsCodesByAccountAndByWhereTheyWent(): void
    {
        $connection = SiteTables::connection();
        $clock = new FixedClock(SiteTables::now());
        $store = new VerificationCodeStore($connection, $clock, new RateLimiters(new InMemoryStorage()), 'secret');
        $store->issue(VerificationPurpose::EmailVerify, VerificationChannel::Email, 'Ada@Example.org');
        $store->issue(VerificationPurpose::PasswordReset, VerificationChannel::Sms, '+44 7700 900123', 7);
        $store->issue(VerificationPurpose::EmailVerify, VerificationChannel::Email, 'grace@example.org');
        $data = new CodeData($store);
        $ada = new UserRef(7, 'ada@example.org', new PhoneNumber('+447700900123'));

        self::assertSame('auth.codes', $data->describe()->key);
        $codes = iterator_to_array($data->export($ada), false);
        self::assertCount(2, $codes);
        self::assertSame(['email_verify', 'email', 0, '2026-11-28T12:00:00Z'], [$codes[0]['purpose'], $codes[0]['channel'], $codes[0]['attempts'], $codes[0]['created_at']]);
        self::assertNull($codes[0]['used_at']);
        self::assertCount(1, iterator_to_array($data->export(new UserRef(7, 'other@example.org')), false), 'by account only');
        self::assertSame(2, $data->erase($ada)->deleted);
        self::assertSame(0, $data->erase($ada)->deleted);
        self::assertSame(0, $store->forgetPerson(99, []));
        self::assertSame(1, Row::int($connection->fetchOne('SELECT COUNT(*) FROM verification_code')));
    }

    public function testRateLimitsCantBeFoundForOnePerson(): void
    {
        $data = new ThrottleData();
        $ada = new UserRef(7, 'ada@example.org');

        self::assertSame(ErasureRule::Keep, $data->describe()->erasure);
        self::assertSame([], iterator_to_array($data->export($ada), false));
        self::assertTrue($data->erase($ada)->isEmpty());
    }
}
