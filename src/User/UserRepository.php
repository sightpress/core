<?php

declare(strict_types=1);

namespace SightPress\Core\User;

use SightPress\Core\Template\Message;
use Doctrine\ORM\EntityManagerInterface;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\Template\ViewerPreferences;
use SightPress\Core\User\Contract\AccountDirectory;
use SightPress\Core\User\Contract\AccountPreferences;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Core\User\Entity\User;

/**
 * The site's users, in the site's own database.
 */
final readonly class UserRepository implements Accounts, AccountDirectory, AccountPreferences
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Gives everyone who chose this language the site's default one again (null), for example after the site stopped
     * being written in it.
     *
     * @return int how many people had chosen it
     */
    public function forgetLocale(string $locale): int
    {
        $changed = $this->entityManager->createQueryBuilder()
            ->update(User::class, 'u')
            ->set('u.locale', ':none')
            ->where('u.locale = :locale')
            ->setParameter('none', null)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->execute();

        return is_int($changed) ? $changed : 0;
    }

    /**
     * Removes the accounts still waiting for their email address to be confirmed that were made before this time.
     *
     * @return int how many were removed
     */
    public function removeUnverified(\DateTimeImmutable $madeBefore): int
    {
        $removed = $this->entityManager->createQueryBuilder()
            ->delete(User::class, 'u')
            ->where('u.status = :status')
            ->andWhere('u.createdAt < :before')
            ->setParameter('status', UserStatus::Unverified->value)
            ->setParameter('before', $madeBefore, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();

        return is_int($removed) ? $removed : 0;
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
            throw new UserException(new Message('Another account already uses {email}.', ['email' => $user->email()]));
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
            throw new UserException(new Message('Only accounts found here can be saved here, not a {class}.', ['class' => $account::class]));
        }

        $this->save($account);
    }

    /**
     * @return list<User> ordered by id
     */
    public function all(): array
    {
        /** @var list<User> */
        return $this->entityManager->getRepository(User::class)->findBy([], ['id' => 'ASC']);
    }

    public function count(): int
    {
        return $this->entityManager->getRepository(User::class)->count();
    }

    public function preferencesOf(int $userId): ?ViewerPreferences
    {
        $user = $this->find($userId);

        return $user === null ? null : new ViewerPreferences($user->locale(), $user->timezone(), $user->adminReducedMotion(), $user->adminLargerText());
    }
}
