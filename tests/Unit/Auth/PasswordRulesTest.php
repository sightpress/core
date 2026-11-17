<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Auth\PasswordPolicy;
use SightPress\Core\Auth\PasswordRules;
use SightPress\Core\Tests\Support\ArraySiteSettings;

#[CoversClass(PasswordRules::class)]
#[UsesClass(PasswordHasher::class)]
#[UsesClass(PasswordPolicy::class)]
final class PasswordRulesTest extends TestCase
{
    public function testAppliesTheSitesPolicyAndHashesWithArgon2id(): void
    {
        $hasher = new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1]);
        $settings = new ArraySiteSettings();
        $rules = new PasswordRules($hasher, $settings);

        self::assertSame('At least 12 characters, including a capital letter, a lower-case letter, a number and a symbol, such as ! ? # or %.', $rules->hint());
        self::assertSame('Your password must be at least 12 characters long.', $rules->problem('short'));
        self::assertSame('Your password must not be your email address.', $rules->problem('nia@example.org', 'nia@example.org'));
        self::assertSame('Your password must include a capital letter, a number and a symbol, such as ! ? # or %.', $rules->problem('correct horse battery staple'));
        self::assertNull($rules->problem('Correct horse 7 battery staple!'));

        $settings->set('auth.password_min_length', '20');
        $settings->set('auth.password_upper', '0');
        $settings->set('auth.password_digit', '0');
        $settings->set('auth.password_symbol', '0');
        self::assertSame(20, $rules->policy()->minLength);
        self::assertSame('At least 20 characters, including a lower-case letter.', $rules->hint());
        self::assertSame('Your password must be at least 20 characters long.', $rules->problem('Correct horse 7!'));
        self::assertNull($rules->problem('correct horse battery staple'));

        $hash = $rules->hash('correct horse battery staple');
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
    }
}
