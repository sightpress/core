<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\User\AccountRegistrar;
use SightPress\Core\User\AvatarKind;
use SightPress\Core\User\Contract\AccountException;

#[CoversClass(AccountRegistrar::class)]
#[CoversClass(AccountException::class)]
#[UsesNamespace('SightPress\Core')]
final class AccountRegistrarTest extends TestCase
{
    public function testCreatesAnActiveAccountWithBothNamesATimeZoneAndAnAvatar(): void
    {
        $auth = new AuthFixture();
        $registrar = new AccountRegistrar($auth->users, $auth->avatars(), $auth->clock);

        $account = $registrar->register('Nia@Example.org', 'Nia', 'Lind', '', 'Asia/Tokyo', 'hash');

        $user = $auth->users->find((int) $account->id());
        self::assertNotNull($user);
        self::assertTrue($user->isActive());
        self::assertSame(['nia@example.org', 'Nia', 'Lind', null, 'Asia/Tokyo', 'hash'], [$user->email(), $user->firstName(), $user->lastName(), $user->displayName(), $user->timezone(), $user->passwordHash()]);
        self::assertSame(AvatarKind::Generated, $user->avatarKind());
        self::assertStringContainsString('>NL</text>', $auth->storage()->read((string) $user->avatarPath()));
    }

    public function testRefusesATakenAddressAndInvalidDetails(): void
    {
        $auth = new AuthFixture();
        $auth->user('ada@example.org');
        $registrar = new AccountRegistrar($auth->users, $auth->avatars(), $auth->clock);

        foreach ([
            'There is already an account for ada@example.org.' => ['ADA@example.org', 'Ada', 'Lovelace', 'UTC'],
            'Enter a first name.' => ['nia@example.org', ' ', 'Lind', 'UTC'],
            '"Mars/Olympus" is not a time zone. Use an IANA time zone such as "Europe/London".' => ['nia@example.org', 'Nia', 'Lind', 'Mars/Olympus'],
        ] as $message => [$email, $first, $last, $timezone]) {
            try {
                $registrar->register($email, $first, $last, null, $timezone, 'hash');
                self::fail('Registered ' . $email);
            } catch (AccountException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
        self::assertSame(1, $auth->users->count());
    }

    public function testCreatesAPendingAccountThatWaitsForItsPassword(): void
    {
        $auth = new AuthFixture();
        $registrar = new AccountRegistrar($auth->users, $auth->avatars(), $auth->clock);

        $account = $registrar->createPending('Grace@Example.org', 'Grace', 'Hopper', 'Europe/London');

        self::assertTrue($account->isPending());
        self::assertNull($account->passwordHash());
        self::assertSame('grace@example.org', $account->email());
        $user = $auth->users->find((int) $account->id());
        self::assertSame(AvatarKind::Generated, $user?->avatarKind());

        $this->expectException(AccountException::class);
        $registrar->createPending('grace@example.org', 'Grace', 'Hopper', 'Europe/London');
    }
}
