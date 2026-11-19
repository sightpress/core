<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Http;

use SightPress\Core\Tenant\Environment;
use SightPress\Core\Tenant\TenantConfig;

/**
 * Checks where the assistant may send a request (SSRF guard, ADR-0040): HTTPS only (plain HTTP only in the `dev`
 * environment), no user name or password in the address, and, unless the tenant sets
 * `assistant.allow_private_hosts`, only hosts whose every address is public: private, loopback, link-local and
 * reserved addresses are refused. The checked address is then the one connected to, so a name can't resolve to a
 * public address for the check and a private one for the request.
 */
final readonly class EndpointGuard
{
    public function __construct(
        private TenantConfig $config,
        private HostResolver $resolver,
    ) {
    }

    /**
     * Why the assistant may not use the address, in plain words, or null when it may.
     */
    public function problem(string $url): ?string
    {
        try {
            $this->check($url);
        } catch (EndpointRefused $refused) {
            return $refused->getMessage();
        }

        return null;
    }

    /**
     * @return array<string, string> host => the address to connect to; empty when private hosts are allowed
     *
     * @throws EndpointRefused when the assistant may not use the address
     */
    public function check(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($parts === false || $host === '' || !in_array($scheme, ['https', 'http'], true)) {
            throw new EndpointRefused('The assistant\'s address must be a full web address, such as https://ai.example.org/v1.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new EndpointRefused('The assistant\'s address must not contain a user name or password. Put the key in the key field instead.');
        }
        if ($scheme === 'http' && $this->config->environment !== Environment::Dev) {
            throw new EndpointRefused('The assistant\'s address must start with https://, so the key and your content are encrypted on the way.');
        }
        if ($this->config->assistant->allowPrivateHosts) {
            return [];
        }
        $addresses = $this->resolver->addresses($host);
        if ($addresses === []) {
            throw new EndpointRefused(sprintf('The assistant\'s address can\'t be found: there is no server called %s.', $host));
        }
        foreach ($addresses as $address) {
            if (!self::isPublic($address)) {
                throw new EndpointRefused(sprintf(
                    'The assistant\'s address %s is on a private or local network, which isn\'t allowed. Your hosting provider can allow it with the tenant setting assistant.allow_private_hosts.',
                    $host,
                ));
            }
        }

        return [$host => $addresses[0]];
    }

    public static function isPublic(string $address): bool
    {
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $mapped) === 1) {
            $address = $mapped[1];
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }
}
