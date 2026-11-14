<?php

declare(strict_types=1);

namespace SightPress\Core\User;

use Doctrine\ORM\EntityManagerInterface;
use SightPress\Core\User\Entity\User;

/**
 * The site's users, in the site's own database.
 */
final readonly class UserRepository
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

    public function count(): int
    {
        return $this->entityManager->getRepository(User::class)->count();
    }
}
