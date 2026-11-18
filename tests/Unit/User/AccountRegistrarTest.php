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

    public function testAnAccountSomeoneMadeForThemselvesWaitsForItsCodeAndGetsItsAvatarOnceConfirmed(): void
    {
        $auth = new AuthFixture();
        $registrar = new AccountRegistrar($auth->users, $auth->avatars(), $auth->clock);

        $account = $registrar->startUnverified('Nia@Example.org', 'Nia', 'Lind', 'Ni', 'Asia/Tokyo', 'pt-PT', 'hash');

        self::assertNotNull($account);
        $user = $auth->users->find((int) $account->id());
        self::assertNotNull($user);
        self::assertTrue($user->awaitsEmailVerification());
        self::assertFalse($user->isActive());
        self::assertSame(['nia@example.org', 'Ni', 'Asia/Tokyo', 'pt-PT', 'hash'], [$user->email(), $user->displayName(), $user->timezone(), $user->locale(), $user->passwordHash()]);
        self::assertNull($user->avatarKind(), 'no avatar until the address is confirmed');

        self::assertSame($user, $registrar->confirm((int) $user->id()));
        self::assertTrue($user->isActive());
        self::assertSame(AvatarKind::Generated, $user->avatarKind());
        self::assertNull($registrar->confirm((int) $user->id()), 'once');
        self::assertNull($registrar->confirm(999));
    }

    public function testSigningUpAgainBeforeTheCodeReplacesTheDetailsButNeverTouchesAnotherAccount(): void
    {
        $auth = new AuthFixture();
        $auth->user('ada@example.org');
        $registrar = new AccountRegistrar($auth->users, $auth->avatars(), $auth->clock);
        $first = $registrar->startUnverified('nia@example.org', 'Someone', 'Else', null, 'UTC', null, 'theirs');

        $again = $registrar->startUnverified('nia@example.org', 'Nia', 'Lind', null, 'Europe/Lisbon', 'es', 'mine');

        self::assertSame($first, $again);
        self::assertNotNull($again);
        $user = $auth->users->find((int) $again->id());
        self::assertNotNull($user);
        self::assertSame(['Nia', 'Lind', 'Europe/Lisbon', 'es', 'mine'], [$user->firstName(), $user->lastName(), $user->timezone(), $user->locale(), $user->passwordHash()]);
        self::assertTrue($user->awaitsEmailVerification());
        self::assertNull($registrar->startUnverified('ADA@example.org', 'Ada', 'Lovelace', null, 'UTC', null, 'hash'), 'an address with an account');
        self::assertTrue($auth->hasher->verify(AuthFixture::PASSWORD, $auth->users->findByEmail('ada@example.org')?->passwordHash()), 'the other account is untouched');

        try {
            $registrar->startUnverified('zoe@example.org', 'Zoe', 'Lind', null, 'Mars/Olympus', null, 'hash');
            self::fail('Made an account with a time zone that doesn’t exist');
        } catch (AccountException $exception) {
            self::assertSame('"Mars/Olympus" is not a time zone. Use an IANA time zone such as "Europe/London".', $exception->getMessage());
        }
    }

    public function testForgetsTheAccountsThatWereNeverConfirmed(): void
    {
        $auth = new AuthFixture();
        $registrar = new AccountRegistrar($auth->users, $auth->avatars(), $auth->clock);
        $auth->user('ada@example.org');
        $old = $registrar->startUnverified('old@example.org', 'Old', 'One', null, 'UTC', null, 'hash');
        $confirmed = $registrar->startUnverified('kept@example.org', 'Kept', 'One', null, 'UTC', null, 'hash');
        $registrar->confirm((int) $confirmed?->id());
        $auth->clock->advance(3 * 86400);
        $registrar->startUnverified('new@example.org', 'New', 'One', null, 'UTC', null, 'hash');
        self::assertNotNull($old);

        self::assertSame(1, $registrar->forgetUnconfirmed($auth->clock->now()->modify('-1 day')));
        $auth->database->entityManager->clear();

        self::assertNull($auth->users->findByEmail('old@example.org'));
        self::assertNotNull($auth->users->findByEmail('kept@example.org'));
        self::assertNotNull($auth->users->findByEmail('new@example.org'));
        self::assertNotNull($auth->users->findByEmail('ada@example.org'));
        self::assertSame(0, $registrar->forgetUnconfirmed($auth->clock->now()->modify('-1 day')));
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
