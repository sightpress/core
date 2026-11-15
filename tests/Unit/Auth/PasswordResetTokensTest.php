<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordResetTokens;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\User\Entity\User;

#[CoversClass(PasswordResetTokens::class)]
#[UsesNamespace('SightPress\Core')]
final class PasswordResetTokensTest extends TestCase
{
    private AuthFixture $auth;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
    }

    public function testATokenNamesTheAccountUntilItExpires(): void
    {
        $user = $this->auth->user();
        $tokens = $this->auth->resetTokens();
        $token = $tokens->create($user);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token);
        self::assertSame($user, $tokens->verify($token));

        $this->auth->clock->advance(PasswordResetTokens::RESET_TTL);
        self::assertSame($user, $tokens->verify($token));
        $this->auth->clock->advance(1);
        self::assertNull($tokens->verify($token));
    }

    public function testAFirstPasswordLinkLastsAWeek(): void
    {
        $user = $this->auth->user(pending: true);
        $token = $this->auth->resetTokens()->create($user, PasswordResetTokens::SETUP_TTL);

        $this->auth->clock->advance(PasswordResetTokens::SETUP_TTL);

        self::assertSame($user, $this->auth->resetTokens()->verify($token));
    }

    public function testATokenStopsWorkingOnceThePasswordChanges(): void
    {
        $user = $this->auth->user();
        $token = $this->auth->resetTokens()->create($user);

        $user->setPasswordHash($this->auth->hasher->hash('a brand new password'), $this->auth->clock->now());

        self::assertNull($this->auth->resetTokens()->verify($token));
    }

    public function testForgedChangedOrForeignTokensAreRefused(): void
    {
        $user = $this->auth->user();
        $token = $this->auth->resetTokens()->create($user);
        [$payload, $signature] = explode('.', $token);
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        $tokens = $this->auth->resetTokens();
        self::assertNull($tokens->verify($payload));
        self::assertNull($tokens->verify($payload . '.' . $encode('forged')));
        self::assertNull($tokens->verify($encode('999:9999999999') . '.' . $signature), 'unknown account');
        self::assertNull($tokens->verify($encode($user->id() . ':9999999999') . '.' . $signature), 'longer expiry');
        self::assertNull($tokens->verify($encode('not a payload') . '.' . $signature));
        self::assertNull($tokens->verify('!!!.' . $signature));

        $otherSite = new PasswordResetTokens($this->auth->users, SiteFixture::site('other'), $this->auth->clock, SiteFixture::SECRET);
        self::assertNull($otherSite->verify($token));
        $otherSecret = new PasswordResetTokens($this->auth->users, $this->auth->site, $this->auth->clock, 'another secret entirely, also long');
        self::assertNull($otherSecret->verify($token));
    }

    public function testAnAccountNeedsAnIdFirst(): void
    {
        $this->expectException(\LogicException::class);

        $this->auth->resetTokens()->create(new User('new@example.org', 'New', 'Person', 'UTC', new DateTimeImmutable()));
    }
}
