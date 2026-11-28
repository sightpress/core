<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use SightPress\Core\Http\Session\SessionStore;
use SightPress\Core\Tenant\Site;

/**
 * A session store of an operator's (`session.store` = this class), built from the site's container: it keeps
 * sessions in memory.
 */
final class SiteSessionStore implements SessionStore
{
    /** @var array<string, array<string, mixed>> */
    private array $sessions = [];

    public function __construct(public readonly Site $site)
    {
    }

    public function read(string $id): ?array
    {
        return $this->sessions[hash('sha256', $id)] ?? null;
    }

    public function write(string $id, array $data): void
    {
        $this->sessions[hash('sha256', $id)] = $data;
    }

    public function touch(string $id): void
    {
    }

    public function delete(string $id): void
    {
        unset($this->sessions[hash('sha256', $id)]);
    }
}
