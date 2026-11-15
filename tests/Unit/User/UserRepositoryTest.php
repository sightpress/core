<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\User;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserException;
use SightPress\Core\User\UserRepository;

#[CoversClass(UserRepository::class)]
#[UsesClass(User::class)]
#[UsesNamespace('SightPress\Core')]
final class UserRepositoryTest extends TestCase
{
    public function testSavesAndFindsUsers(): void
    {
        $database = new CoreSiteDatabase();
        $users = new UserRepository($database->entityManager);
        $user = new User('Ada@Example.org', 'Ada', 'Lovelace', 'UTC', new DateTimeImmutable());

        self::assertSame(0, $users->count());
        $users->save($user);
        $users->save($user);

        self::assertIsInt($user->id());
        self::assertSame(1, $users->count());
        self::assertSame($user, $users->find($user->id()));
        self::assertSame($user, $users->findByEmail(' ADA@example.org '));
        self::assertNull($users->findByEmail('grace@example.org'));
        self::assertNull($users->find(999));
    }

    public function testServesAccountsToSignIn(): void
    {
        $users = new UserRepository(new CoreSiteDatabase()->entityManager);
        $user = new User('ada@example.org', 'Ada', 'Lovelace', 'UTC', new DateTimeImmutable());
        $users->saveAccount($user);

        self::assertSame($user, $users->findAccount((int) $user->id()));
        self::assertSame($user, $users->findAccountByEmail('ADA@example.org'));
        self::assertNull($users->findAccount(999));

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Only accounts found here can be saved here');
        $users->saveAccount(new class () implements Account {
            public function id(): ?int
            {
                return null;
            }

            public function email(): string
            {
                return 'x@example.org';
            }

            public function effectiveName(): string
            {
                return 'X';
            }

            public function isActive(): bool
            {
                return false;
            }

            public function isPending(): bool
            {
                return true;
            }

            public function passwordHash(): ?string
            {
                return null;
            }

            public function setPasswordHash(string $hash, DateTimeImmutable $now): void
            {
            }
        });
    }

    public function testAnEmailAddressBelongsToOneAccount(): void
    {
        $database = new CoreSiteDatabase();
        $users = new UserRepository($database->entityManager);
        $users->save(new User('ada@example.org', 'Ada', 'Lovelace', 'UTC', new DateTimeImmutable()));

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Another account already uses ada@example.org.');

        $users->save(new User('ADA@example.org', 'Ada', 'King', 'UTC', new DateTimeImmutable()));
    }
}
