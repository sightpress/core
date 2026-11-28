<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

/**
 * Where session data is kept between requests: the site's database ({@see DatabaseSessionStore}, the default),
 * Redis ({@see RedisSessionStore}) or files on one server ({@see FileSessionStore}), chosen with the tenant setting
 * `session.store`, which may also name a class of your own implementing this interface. Each site has its own store.
 *
 * Public API (H-7): implementations outside core may rely on it. A store never keeps a session id as it is (only a
 * hash of it), ends a session that had no request for the idle timeout, and passes the session contract tests
 * (`tests/Integration/Http/Session/SessionStoreContract.php`).
 *
 * @api
 */
interface SessionStore
{
    /**
     * The data of a session, or null when it doesn't exist or has expired.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $id): ?array;

    /**
     * @param array<string, mixed> $data
     */
    public function write(string $id, array $data): void;

    /**
     * Restarts a session's idle time without changing its data. A session that no longer exists, or has expired,
     * stays gone.
     */
    public function touch(string $id): void;

    public function delete(string $id): void;
}
