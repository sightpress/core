<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Http;

/**
 * Resolves host names with the system resolver: IPv4 (`gethostbynamel()`) and IPv6 (`AAAA` records). A host
 * written as an address is returned as it is.
 */
final readonly class SystemHostResolver implements HostResolver
{
    public function addresses(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $addresses = gethostbynamel($host);
        $addresses = $addresses === false ? [] : $addresses;
        $records = @dns_get_record($host, DNS_AAAA);
        foreach ($records === false ? [] : $records as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
