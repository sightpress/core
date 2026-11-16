<?php

declare(strict_types=1);

namespace SightPress\Core\User\Contract;

/**
 * Several accounts at once, for example everyone who gets a notification.
 */
interface AccountDirectory
{
    /**
     * @param list<int> $ids
     *
     * @return list<Account> the accounts that exist, ordered by id
     */
    public function findAccounts(array $ids): array;
}
