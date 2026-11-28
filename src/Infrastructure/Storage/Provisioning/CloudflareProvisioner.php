<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Storage\Provisioning;

use JsonException;
use SightPress\Core\Assistant\Http\BackoffStrategy;
use SightPress\Core\Assistant\Http\EndpointGuard;
use SightPress\Core\Assistant\Http\EndpointRefused;
use SightPress\Core\Template\Message;
use SightPress\Core\Infrastructure\Http\OutboundRequestRefused;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * R2 bucket with browser-upload CORS and clean-up rules, through the shared HTTP client with the assistant's rules
 * (ADR-0040): checked address, no redirects, limited retries. An R2 custom domain makes a whole bucket public, so it
 * is only ever attached to a separate `<bucket>-public` bucket, never to the one holding private files.
 */
final readonly class CloudflareProvisioner implements CloudProvisioner
{
    private const string API = 'https://api.cloudflare.com/client/v4/accounts/';

    private const int TIMEOUT = 30;

    private const int MAX_DURATION = 90;

    private const int RETRIES = 2;

    public const string CORS_ID = 'sightpress-direct-upload';

    public const string ABORT_RULE = 'sightpress-abort-multipart';

    public const string INCOMING_RULE = 'sightpress-expire-incoming';

    public function __construct(
        private HttpClientInterface $client,
        private EndpointGuard $guard,
        private string $accountId,
        #[\SensitiveParameter]
        private string $token,
        private ?string $zoneId = null,
        private int $retryDelayMs = 500,
    ) {
    }

    public function plan(ProvisioningRequest $request): ProvisioningPlan
    {
        $steps = [
            new Message('A private R2 bucket “{bucket}” in the {region} jurisdiction', ['bucket' => $request->bucket, 'region' => strtoupper($request->region)]),
            new Message('Browser uploads allowed only from: {origins}', ['origins' => implode(', ', $request->adminOrigins)]),
            new Message('Unfinished uploads removed after 1 day, and temporary uploads after 2 days'),
        ];
        if ($request->customDomain !== null) {
            $steps[] = new Message('A separate public bucket “{bucket}” at {domain}. Private files are never put in it', ['bucket' => $request->bucket . '-public', 'domain' => $request->customDomain]);
        }

        return new ProvisioningPlan('Cloudflare', strtoupper($request->region), $steps, new Message('Files stay in the location above. R2 has no UK-only location. Files in a public bucket are cached worldwide by Cloudflare, which is an international transfer.'));
    }

    public function provision(ProvisioningRequest $request): ProvisioningResult
    {
        if ($request->customDomain !== null && $this->zoneId === null) {
            throw new ProvisioningException('Set SIGHTPRESS_CLOUDFLARE_ZONE_ID to use a custom domain.');
        }
        $created = [];
        $unchanged = [];
        $this->bucket($request, $request->bucket) ? $created[] = 'bucket' : $unchanged[] = 'bucket';
        $this->cors($request);
        $this->lifecycle($request);
        if ($request->customDomain === null) {
            return new ProvisioningResult($created, $unchanged);
        }
        $public = $request->bucket . '-public';
        $this->bucket($request, $public) ? $created[] = 'public bucket' : $unchanged[] = 'public bucket';
        [, $domains] = $this->call($request, 'GET', $public . '/domains/custom');
        $result = $domains['result'] ?? null;
        $attached = is_array($result) && is_array($result['domains'] ?? null) ? array_column($result['domains'], 'domain') : [];
        if (in_array($request->customDomain, $attached, true)) {
            $unchanged[] = 'custom domain';
        } else {
            $this->call($request, 'POST', $public . '/domains/custom', ['domain' => $request->customDomain, 'zoneId' => $this->zoneId, 'enabled' => true, 'minTLS' => '1.2']);
            $created[] = 'custom domain';
        }

        return new ProvisioningResult($created, $unchanged, $request->customDomain);
    }

    private function bucket(ProvisioningRequest $request, string $name): bool
    {
        [$status] = $this->call($request, 'GET', $name, null, [404]);
        if ($status !== 404) {
            return false;
        }
        $this->call($request, 'POST', '', ['name' => $name]);

        return true;
    }

    private function cors(ProvisioningRequest $request): void
    {
        [$status, $data] = $this->call($request, 'GET', $request->bucket . '/cors', null, [404]);
        $rules = $status === 404 ? [] : self::rules($data);
        $rules = array_values(array_filter($rules, static fn (array $rule): bool => ($rule['id'] ?? null) !== self::CORS_ID));
        $rules[] = ['id' => self::CORS_ID, 'allowed' => ['methods' => ['PUT'], 'origins' => $request->adminOrigins, 'headers' => ['*']], 'exposeHeaders' => ['ETag'], 'maxAgeSeconds' => 3600];
        $this->call($request, 'PUT', $request->bucket . '/cors', ['rules' => $rules]);
    }

    private function lifecycle(ProvisioningRequest $request): void
    {
        $tag = substr(hash('sha256', $request->prefix), 0, 32);
        $ours = [self::ABORT_RULE . '-' . $tag, self::INCOMING_RULE . '-' . $tag];
        [$status, $data] = $this->call($request, 'GET', $request->bucket . '/lifecycle', null, [404]);
        $rules = $status === 404 ? [] : self::rules($data);
        $rules = array_values(array_filter($rules, static fn (array $rule): bool => !in_array($rule['id'] ?? null, $ours, true)));
        $rules[] = ['id' => $ours[0], 'enabled' => true, 'conditions' => ['prefix' => $request->prefix . '/'], 'abortMultipartUploadsTransition' => ['condition' => ['type' => 'Age', 'maxAge' => 86400]]];
        $rules[] = ['id' => $ours[1], 'enabled' => true, 'conditions' => ['prefix' => $request->incomingPrefix()], 'deleteObjectsTransition' => ['condition' => ['type' => 'Age', 'maxAge' => 172800]]];
        $this->call($request, 'PUT', $request->bucket . '/lifecycle', ['rules' => $rules]);
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<array-key, mixed>>
     */
    private static function rules(array $data): array
    {
        $result = $data['result'] ?? null;
        $rules = is_array($result) ? ($result['rules'] ?? []) : [];

        return is_array($rules) ? array_values(array_filter($rules, is_array(...))) : [];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param list<int>                 $allowed error statuses the caller handles
     *
     * @return array{0: int, 1: array<mixed>}
     */
    private function call(ProvisioningRequest $request, string $method, string $path, ?array $body = null, array $allowed = []): array
    {
        $url = self::API . rawurlencode($this->accountId) . '/r2/buckets' . ($path === '' ? '' : '/' . $path);
        try {
            $resolve = $this->guard->check($url);
            $options = [
                'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json', 'cf-r2-jurisdiction' => $request->region],
                'timeout' => self::TIMEOUT,
                'max_duration' => self::MAX_DURATION,
                'max_redirects' => 0,
                'resolve' => $resolve,
            ];
            if ($body !== null) {
                $options['json'] = $body;
            }
            $response = new RetryableHttpClient($this->client, new BackoffStrategy($this->retryDelayMs), self::RETRIES)->request($method, $url, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (OutboundRequestRefused $refused) {
            throw new ProvisioningException($refused->getMessage());
        } catch (EndpointRefused | TransportExceptionInterface) {
            throw new ProvisioningException('Cloudflare can\'t be reached. Check the network and try again.');
        }
        if (in_array($status, $allowed, true)) {
            return [$status, []];
        }
        if ($status >= 300) {
            throw new ProvisioningException(match (true) {
                $status === 401, $status === 403 => 'Cloudflare refused the API token. Check that it can edit R2 for this account.',
                $status === 429 => 'Cloudflare is busy. Try again in a few minutes.',
                default => sprintf('Cloudflare refused the request (HTTP %d).', $status),
            });
        }
        try {
            $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProvisioningException('Cloudflare gave an answer that can\'t be read.');
        }

        return [$status, is_array($data) ? $data : []];
    }
}
