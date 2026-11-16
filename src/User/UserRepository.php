<?php

declare(strict_types=1);

namespace SightPress\Core\User;

use Doctrine\ORM\EntityManagerInterface;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\AccountDirectory;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Core\User\Entity\User;

/**
 * The site's users, in the site's own database.
 */
final readonly class UserRepository implements Accounts, AccountDirectory
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(int $id): ?User
    {
        return $this->entityManager->find(User::class, $id);
    }

    /**
     * Finds a user by email address, whatever its case or surrounding spaces.
     */
    public function findAccounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<User> */
        return $this->entityManager->getRepository(User::class)->findBy(['id' => array_values(array_unique($ids))], ['id' => 'ASC']);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => User::normalizeEmail($email)]);
    }

    /**
     * Saves a new user (which gets its id) or the changes to an existing one.
     *
     * @throws UserException when another account already uses the email address
     */
    public function save(User $user): void
    {
        $existing = $this->findByEmail($user->email());
        if ($existing !== null && $existing !== $user) {
            throw new UserException(sprintf('Another account already uses %s.', $user->email()));
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    public function findAccount(int $id): ?Account
    {
        return $this->find($id);
    }

    public function findAccountByEmail(string $email): ?Account
    {
        return $this->findByEmail($email);
    }

    public function saveAccount(Account $account): void
    {
        if (!$account instanceof User) {
            throw new UserException(sprintf('Only accounts found here can be saved here, not a %s.', $account::class));
        }

        $this->save($account);
    }

    public function count(): int
    {
        return $this->entityManager->getRepository(User::class)->count();
    }
}
