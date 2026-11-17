<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Auth\PasswordPolicy;
use SightPress\Core\Tests\Support\ArraySiteSettings;

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
        yield 'meets the default policy' => ['Correct horse 7 battery!', 'ada@example.org', null];
        yield 'twelve characters, counted as characters not bytes, in any script' => ['Éééééééééé1€', '', null];
        yield 'too short' => ['Sh0rt pass!', '', 'Your password must be at least 12 characters long.'];
        yield 'too long' => [str_repeat('Aa1!', 64) . 'a', '', 'Your password must be 256 characters or fewer.'];
        yield 'the email address' => [' ADA@example.org1 ', 'ada@example.org1', 'Your password must not be your email address.'];
        yield 'no capital letter' => ['correct horse 7 battery!', '', 'Your password must include a capital letter.'];
        yield 'no lower-case letter' => ['CORRECT HORSE 7 BATTERY!', '', 'Your password must include a lower-case letter.'];
        yield 'no number' => ['Correct horse battery!', '', 'Your password must include a number.'];
        yield 'a space is not a symbol' => ['Correct horse 7 battery', '', 'Your password must include a symbol, such as ! ? # or %.'];
        yield 'everything missing is named at once' => ['correct horse battery', '', 'Your password must include a capital letter, a number and a symbol, such as ! ? # or %.'];
    }

    #[DataProvider('passwords')]
    public function testTheDefaultPolicyExplainsWhatIsWrong(string $password, string $email, ?string $problem): void
    {
        self::assertSame($problem, new PasswordPolicy()->problem($password, $email));
    }

    public function testTheDefaultPolicyIsTwelveCharactersWithEveryKind(): void
    {
        $policy = new PasswordPolicy();

        self::assertSame(12, $policy->minLength);
        self::assertTrue($policy->upper && $policy->lower && $policy->digit && $policy->symbol);
        self::assertSame('At least 12 characters, including a capital letter, a lower-case letter, a number and a symbol, such as ! ? # or %.', $policy->hint());
    }

    public function testASiteCanChangeItsPolicy(): void
    {
        $policy = new PasswordPolicy(16, upper: false, lower: true, digit: false, symbol: false);

        self::assertSame('At least 16 characters, including a lower-case letter.', $policy->hint());
        self::assertSame('Your password must be at least 16 characters long.', $policy->problem('correct horse'));
        self::assertNull($policy->problem('correct horse battery'));

        $plain = new PasswordPolicy(10, false, false, false, false);
        self::assertSame('At least 10 characters. A few unrelated words make a strong password that is easy to remember.', $plain->hint());
        self::assertNull($plain->problem('0123456789'));
    }

    public function testTheMinimumLengthStaysWithinItsLimits(): void
    {
        self::assertSame(8, new PasswordPolicy(4)->minLength);
        self::assertSame(256, new PasswordPolicy(1000)->minLength);
    }

    /**
     * @return iterable<string, array{array<string, string>, int, bool}>
     */
    public static function stored(): iterable
    {
        yield 'nothing stored: the default' => [[], 12, true];
        yield 'a site\'s own choice' => [['auth.password_min_length' => '14', 'auth.password_symbol' => '0'], 14, false];
        yield 'a broken length: the default' => [['auth.password_min_length' => 'twelve', 'auth.password_symbol' => '1'], 12, true];
        yield 'an empty length: the default' => [['auth.password_min_length' => ''], 12, true];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('stored')]
    public function testReadsThePolicyFromTheSiteSettings(array $values, int $length, bool $symbol): void
    {
        $policy = PasswordPolicy::fromSettings(new ArraySiteSettings($values));

        self::assertSame($length, $policy->minLength);
        self::assertSame($symbol, $policy->symbol);
        self::assertTrue($policy->upper && $policy->lower && $policy->digit);
    }
}
