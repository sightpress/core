<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

/**
 * Where session data is kept between requests.
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

    public function delete(string $id): void;
}
