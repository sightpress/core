<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Http;

use LogicException;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Every outgoing request goes through here (GD-1, ADR-0075): it wraps the tenant's one HTTP client (ADR-0040) and
 * refuses, before anything is sent, a request to a host that {@see OutboundHosts} doesn't allow. Redirects are never
 * followed, so a declared host can't send the request on to one that isn't. The callers keep their own rules on top
 * (the assistant's private-network check, timeouts and retries).
 */
final readonly class OutboundHttp implements HttpClientInterface
{
    /** The container id of the tenant's client without the allow-list, for the server's own checks. */
    public const string INNER = 'sightpress.http.inner';

    /**
     * Hosts every site and the tenant may call: Cloudflare's API, which `site:storage:provision` uses to make R2
     * buckets (AWS is reached through its own SDK).
     */
    public const array TENANT_HOSTS = ['api.cloudflare.com'];

    /**
     * @param string|null $baseUri the `base_uri` given to {@see withOptions()}, which relative addresses go to
     */
    public function __construct(
        private HttpClientInterface $client,
        private OutboundHosts $hosts,
        private ?string $baseUri = null,
    ) {
    }

    /**
     * @param array<mixed> $options
     *
     * @throws OutboundRequestRefused when no declared service uses the host
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $host = self::hostOf($url, $options['base_uri'] ?? $this->baseUri);
        if (!$this->hosts->allows($host)) {
            throw new OutboundRequestRefused($host);
        }

        return $this->client->request($method, $url, ['max_redirects' => 0] + $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    /**
     * @param array<mixed> $options
     */
    public function withOptions(array $options): static
    {
        $baseUri = $options['base_uri'] ?? null;

        return new static($this->client->withOptions(['max_redirects' => 0] + $options), $this->hosts, is_string($baseUri) ? $baseUri : $this->baseUri);
    }

    /**
     * The tenant's client without the allow-list ({@see INNER}).
     */
    public static function inner(ContainerInterface $container): HttpClientInterface
    {
        $client = $container->get(self::INNER);

        return $client instanceof HttpClientInterface ? $client : throw new LogicException(sprintf('Service %s is not an HTTP client.', self::INNER));
    }

    public function hosts(): OutboundHosts
    {
        return $this->hosts;
    }

    private static function hostOf(string $url, mixed $baseUri): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ((!is_string($host) || $host === '') && is_string($baseUri)) {
            $host = parse_url($baseUri, PHP_URL_HOST);
        }

        return is_string($host) ? strtolower(trim($host, '[]')) : '';
    }
}
