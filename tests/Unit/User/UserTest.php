<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\User;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\User\AvatarKind;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserException;
use SightPress\Core\User\UserStatus;

#[CoversClass(User::class)]
#[UsesClass(LocaleCode::class)]
final class UserTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-11-14T12:00:00Z');
    }

    public function testANewUserIsPendingWithTrimmedDetails(): void
    {
        $user = new User('  Zoe@Example.ORG ', ' Zoë ', ' Ødegaard ', 'Europe/Oslo', $this->now, '  ', 'nb-NO');

        self::assertNull($user->id());
        self::assertSame('zoe@example.org', $user->email());
        self::assertSame('Zoë', $user->firstName());
        self::assertSame('Ødegaard', $user->lastName());
        self::assertNull($user->displayName());
        self::assertSame('Zoë', $user->effectiveName());
        self::assertSame('Zoë Ødegaard', $user->fullName());
        self::assertSame('Europe/Oslo', $user->timezone());
        self::assertSame('nb-NO', $user->locale());
        self::assertSame(UserStatus::Pending, $user->status());
        self::assertNull($user->passwordHash());
        self::assertNull($user->avatarPath());
        self::assertNull($user->avatarKind());
        self::assertNull($user->avatarColor());
        self::assertSame($this->now, $user->createdAt());
        self::assertSame($this->now, $user->updatedAt());
    }

    public function testTheDisplayNameWinsWhenSet(): void
    {
        $user = $this->user();
        $later = $this->now->modify('+1 hour');

        $user->rename('李', '小龙', 'Bruce', $later);

        self::assertSame('李', $user->firstName());
        self::assertSame('小龙', $user->lastName());
        self::assertSame('Bruce', $user->effectiveName());
        self::assertSame($later, $user->updatedAt());
    }

    /**
     * @return iterable<string, array{string, string, string, string, ?string, ?string, string}>
     */
    public static function invalidDetails(): iterable
    {
        $long = str_repeat('é', 101);
        yield 'bad email' => ['not-an-email', 'A', 'B', 'UTC', null, null, 'is not a valid email address'];
        yield 'long email' => [str_repeat('a', 250) . '@x.org', 'A', 'B', 'UTC', null, null, 'is not a valid email'];
        yield 'empty first name' => ['a@x.org', '  ', 'B', 'UTC', null, null, 'Enter a first name.'];
        yield 'long last name' => ['a@x.org', 'A', $long, 'UTC', null, null, 'The last name must be 100 characters or fewer.'];
        yield 'long display name' => ['a@x.org', 'A', 'B', 'UTC', $long, null, 'The display name must be'];
        yield 'bad time zone' => ['a@x.org', 'A', 'B', 'Mars/Olympus', null, null, 'is not a time zone'];
        yield 'bad locale' => ['a@x.org', 'A', 'B', 'UTC', null, 'english', 'is not a locale code'];
    }

    #[DataProvider('invalidDetails')]
    public function testInvalidDetailsAreRefused(
        string $email,
        string $first,
        string $last,
        string $timezone,
        ?string $display,
        ?string $locale,
        string $message,
    ): void {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage($message);

        new User($email, $first, $last, $timezone, $this->now, $display, $locale);
    }

    public function testTimezoneAndLocaleChange(): void
    {
        $user = $this->user();

        $user->changeTimezone('America/Sao_Paulo', $this->now);
        $user->changeLocale('pt-BR', $this->now);
        self::assertSame('America/Sao_Paulo', $user->timezone());
        self::assertSame('pt-BR', $user->locale());

        $user->changeLocale(null, $this->now);
        self::assertNull($user->locale());

        $this->expectException(UserException::class);
        $user->changeTimezone('Nowhere', $this->now);
    }

    public function testSettingAPasswordActivatesAPendingAccount(): void
    {
        $user = $this->user();

        $user->setPasswordHash('$argon2id$hash', $this->now);

        self::assertSame('$argon2id$hash', $user->passwordHash());
        self::assertSame(UserStatus::Active, $user->status());
    }

    public function testADeactivatedAccountStaysDeactivatedWhenThePasswordChanges(): void
    {
        $user = $this->user();
        $user->deactivate($this->now);

        $user->setPasswordHash('$argon2id$hash', $this->now);
        self::assertSame(UserStatus::Deactivated, $user->status());

        $user->activate($this->now);
        self::assertSame(UserStatus::Active, $user->status());
    }

    public function testAnEmptyPasswordHashIsRefused(): void
    {
        $this->expectException(UserException::class);

        $this->user()->setPasswordHash('', $this->now);
    }

    public function testAnAccountWithoutAPasswordCantBeActivated(): void
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('set a password first');

        $this->user()->activate($this->now);
    }

    public function testTheAvatarIsStored(): void
    {
        $user = $this->user();

        $user->setAvatar(AvatarKind::Generated, 'avatars/1.svg', '#1d4ed8', $this->now);

        self::assertSame(AvatarKind::Generated, $user->avatarKind());
        self::assertSame('avatars/1.svg', $user->avatarPath());
        self::assertSame('#1d4ed8', $user->avatarColor());
    }

    public function testAnAvatarNeedsAPathAndAColour(): void
    {
        $user = $this->user();

        try {
            $user->setAvatar(AvatarKind::Uploaded, '', '#000000', $this->now);
            self::fail('An empty path was accepted.');
        } catch (UserException $exception) {
            self::assertStringContainsString('file path', $exception->getMessage());
        }

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('#rrggbb');
        $user->setAvatar(AvatarKind::Uploaded, 'avatars/a.webp', 'blue', $this->now);
    }

    private function user(): User
    {
        return new User('ada@example.org', 'Ada', 'Lovelace', 'Europe/London', $this->now);
    }
}
