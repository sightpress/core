<?php

declare(strict_types=1);

namespace SightPress\Core\User;

/**
 * Whether an account can sign in.
 */
enum UserStatus: string
{
    /** The account exists but the person hasn't set a password yet (first admin, invite not accepted). */
    case Pending = 'pending';

    /** The person can sign in. */
    case Active = 'active';

    /** An admin switched the account off; the person can't sign in, their content stays. */
    case Deactivated = 'deactivated';
}
