<?php

declare(strict_types=1);

namespace SightPress\Core\User\Contract;

/**
 * Creates accounts that wait for their first password, for the admins an operator makes from the command line. The
 * account has both names, a time zone and a generated avatar; the person chooses their password with a setup code.
 */
interface PendingAccounts
{
    /**
     * @param string $timezone IANA time zone
     *
     * @throws AccountException when the email address is already used, or a name or the time zone isn't valid
     */
    public function createPending(string $email, string $firstName, string $lastName, string $timezone): Account;
}
