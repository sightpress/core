<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\User;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
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
