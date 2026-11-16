<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Auth\PasswordPolicy;
use SightPress\Core\Auth\PasswordRules;

#[CoversClass(PasswordRules::class)]
#[UsesClass(PasswordHasher::class)]
#[UsesClass(PasswordPolicy::class)]
final class PasswordRulesTest extends TestCase
{
    public function testAppliesThePolicyAndHashesWithArgon2id(): void
    {
        $hasher = new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1]);
        $rules = new PasswordRules($hasher);

        self::assertSame('Your password must be at least 12 characters long.', $rules->problem('short'));
        self::assertSame('Your password must not be your email address.', $rules->problem('nia@example.org', 'nia@example.org'));
        self::assertNull($rules->problem('correct horse battery staple'));
        $hash = $rules->hash('correct horse battery staple');
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
    }
}
