<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\User;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Contract\AccountException;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * The unique index on `users.phone_e164` (N-3), on every database engine: any number of accounts without a number
 * (NULL), one account per number, and the repository refusing a second account before the database has to.
 */
#[CoversNothing]
final class PhoneNumberUniqueTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('phone_unique');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testManyAccountsWithoutANumberAndOneAccountPerNumber(): void
    {
        $users = Services::get($this->site->container(), UserRepository::class);
        $ids = [];
        foreach (['ada', 'bea', 'cleo'] as $name) {
            $user = new User($name . '@example.org', ucfirst($name), 'Test', 'UTC', new DateTimeImmutable());
            $users->save($user);
            $ids[$name] = (int) $user->id();
        }
        $users->confirmPhone($ids['ada'], '+447400123456', 'mobile GB');
        self::assertSame($ids['ada'], $users->accountWithPhone('+447400123456'));
        self::assertNull($users->accountWithPhone('+447400123457'));

        try {
            $users->confirmPhone($ids['bea'], '+447400123456', null);
            self::fail('Two accounts got one number.');
        } catch (AccountException) {
        }

        $connection = Services::get($this->site->container(), Connection::class);
        $this->expectException(UniqueConstraintViolationException::class);
        $connection->update('users', ['phone_e164' => '+447400123456'], ['id' => $ids['cleo']]);
    }
}
