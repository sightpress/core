<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

use DateTimeImmutable;

/**
 * A session store that needs expired sessions removed now and then: `schedule:run` calls {@see forgetExpired()}
 * once a minute for each site ({@see ForgetExpiredSessions}). Stores whose server ends sessions itself (Redis) don't
 * implement it.
 *
 * @api
 */
interface ExpiringSessionStore extends SessionStore
{
    /**
     * Removes the sessions that expired by $now.
     *
     * @return int how many were removed
     */
    public function forgetExpired(DateTimeImmutable $now): int;
}
