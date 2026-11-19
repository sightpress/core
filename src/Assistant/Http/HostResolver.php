<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Http;

/**
 * Looks up the addresses of a host name, so the assistant can refuse providers on private networks (SSRF) and then
 * connect to exactly the address it checked.
 */
interface HostResolver
{
    /**
     * @return list<string> IPv4 and IPv6 addresses; empty when the name doesn't resolve
     */
    public function addresses(string $host): array;
}
