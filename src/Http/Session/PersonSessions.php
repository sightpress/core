<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

/**
 * A session store that can find the sessions of one signed-in person ({@see Session::USER_ID}), for *Download your
 * data* and *Delete your account*. Core's three stores implement it; a store of your own should too, or a person's
 * sessions stay until they end by themselves.
 *
 * @api
 */
interface PersonSessions extends SessionStore
{
    /**
     * When each of the person's sessions was last used and when it ends (UTC), never the id or the data.
     *
     * @return list<array{last_seen_at: string|null, expires_at: string|null}>
     */
    public function forPerson(int $userId): array;

    /**
     * Ends every session of the person, on every device.
     *
     * @return int how many were ended
     */
    public function forgetPerson(int $userId): int;
}
