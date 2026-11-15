<?php

declare(strict_types=1);

namespace SightPress\Core\User\Contract;

/**
 * The site's accounts, for sign-in.
 */
interface Accounts
{
    public function findAccount(int $id): ?Account;

    /**
     * Finds an account by email address, whatever its case or surrounding spaces.
     */
    public function findAccountByEmail(string $email): ?Account;

    /**
     * Saves the changes to an account found here.
     */
    public function saveAccount(Account $account): void;
}
