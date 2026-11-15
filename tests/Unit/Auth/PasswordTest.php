<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Auth\PasswordPolicy;

#[CoversClass(PasswordHasher::class)]
#[CoversClass(PasswordPolicy::class)]
final class PasswordTest extends TestCase
{
    private const array CHEAP = ['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1];

    public function testHashesWithArgon2idAndChecksThePassword(): void
    {
        $hasher = new PasswordHasher(self::CHEAP);
        $hash = $hasher->hash('correct horse battery staple');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
        self::assertFalse($hasher->verify('Correct horse battery staple', $hash));
        self::assertFalse($hasher->needsRehash($hash));
        self::assertTrue(new PasswordHasher(['memory_cost' => 16, 'time_cost' => 1, 'threads' => 1])->needsRehash($hash));
    }

    public function testAMissingHashNeverMatchesButStillDoesTheWork(): void
    {
        $hasher = new PasswordHasher(self::CHEAP);

        self::assertFalse($hasher->verify('not a real password', null));
        self::assertFalse($hasher->verify('anything', null));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function passwords(): iterable
    {
        yield 'long enough' => ['correct horse battery staple', 'ada@example.org', null];
        yield 'twelve characters, counted as characters not bytes' => ['ééééééééééé1', '', null];
        yield 'too short' => ['short pass', '', 'Your password must be at least 12 characters long.'];
        yield 'too long' => [str_repeat('a', 257), '', 'Your password must be 256 characters or fewer.'];
        yield 'the email address' => [' ADA@example.org ', 'ada@example.org', 'Your password must not be your email address.'];
    }

    #[DataProvider('passwords')]
    public function testThePolicyExplainsWhatIsWrong(string $password, string $email, ?string $problem): void
    {
        self::assertSame($problem, PasswordPolicy::problem($password, $email));
    }
}
