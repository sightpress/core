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
    public function testRemovesOnlyUnverifiedAccountsMadeBeforeAGivenTime(): void
    {
        $database = new CoreSiteDatabase();
        $users = new UserRepository($database->entityManager);
        $made = new DateTimeImmutable('2026-11-01 09:00:00 UTC');
        foreach (['old@example.org' => $made, 'new@example.org' => $made->modify('+3 days'), 'active@example.org' => $made] as $email => $at) {
            $user = new User($email, 'A', 'B', 'UTC', $at);
            $user->setPasswordHash('hash', $at);
            if ($email !== 'active@example.org') {
                $user->awaitEmailVerification($at);
            }
            $users->save($user);
        }

        self::assertSame(1, $users->removeUnverified($made->modify('+1 day')));
        $database->entityManager->clear();

        self::assertNull($users->findByEmail('old@example.org'));
        self::assertNotNull($users->findByEmail('new@example.org'));
        self::assertNotNull($users->findByEmail('active@example.org'));
    }

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

    public function testFindsSeveralAccountsAtOnce(): void
    {
        $database = new CoreSiteDatabase();
        $users = new UserRepository($database->entityManager);
        $ada = new User('ada@example.org', 'Ada', 'Lovelace', 'UTC', new DateTimeImmutable());
        $grace = new User('grace@example.org', 'Grace', 'Hopper', 'UTC', new DateTimeImmutable());
        $users->save($ada);
        $users->save($grace);

        self::assertSame([$ada, $grace], $users->findAccounts([(int) $grace->id(), 999, (int) $ada->id(), (int) $grace->id()]));
        self::assertSame([], $users->findAccounts([]));
        self::assertSame([$ada, $grace], $users->all());
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

            public function awaitsEmailVerification(): bool
            {
                return false;
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

    public function testKnowsEachPersonsDisplayPreferences(): void
    {
        $database = new \SightPress\Core\Tests\Support\CoreSiteDatabase();
        $users = new UserRepository($database->entityManager);
        $user = new User('ana@example.org', 'Ana', 'Silva', 'America/Sao_Paulo', new \DateTimeImmutable(), locale: 'pt-BR');
        $users->save($user);

        self::assertEquals(new \SightPress\Core\Template\ViewerPreferences('pt-BR', 'America/Sao_Paulo'), $users->preferencesOf((int) $user->id()));
        self::assertNull($users->preferencesOf(999));
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
