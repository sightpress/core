<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * An S3 bucket in memory, as the SDK's `http_handler`: enough of the API for the storage adapter. Set `$errorCode` to
 * make every request fail with that S3 error.
 */
final class FakeS3
{
    /** @var array<string, string> object key => contents */
    public array $objects = [];

    public ?string $errorCode = null;

    /** Writes store this instead of what was sent, to test checksum checks. */
    public ?string $corruptWrites = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(RequestInterface $request, array $options = []): PromiseInterface
    {
        $response = $this->answer($request);
        if ($response->getStatusCode() < 400) {
            return Create::promiseFor($response);
        }

        // The SDK's HTTP handlers reject on an error status; it reads the S3 error from this array.
        return Create::rejectionFor([
            'exception' => new RuntimeException('S3 error'),
            'connection_error' => false,
            'response' => $response,
        ]);
    }

    private function answer(RequestInterface $request): Response
    {
        if ($this->errorCode !== null) {
            return new Response(403, ['Content-Type' => 'application/xml'], sprintf(
                '<?xml version="1.0"?><Error><Code>%s</Code><Message>Key AKIASECRETVALUE refused</Message></Error>',
                $this->errorCode,
            ));
        }
        parse_str($request->getUri()->getQuery(), $query);
        // Path-style addresses start with the bucket; virtual-hosted ones have it in the host name.
        $path = $request->getUri()->getPath();
        $key = rawurldecode(str_contains($request->getUri()->getHost(), 'amazonaws.com') && substr_count($request->getUri()->getHost(), '.') > 3
            ? ltrim($path, '/')
            : (string) preg_replace('#^/[^/]+/?#', '', $path));
        $method = $request->getMethod();
        if ($method === 'POST' && array_key_exists('delete', $query)) {
            preg_match_all('#<Key>(.*?)</Key>#', (string) $request->getBody(), $keys);
            foreach ($keys[1] as $deleted) {
                unset($this->objects[html_entity_decode($deleted)]);
            }

            return new Response(200, [], '<?xml version="1.0"?><DeleteResult/>');
        }
        if ($method === 'GET' && $key === '') {
            return $this->listing(is_string($query['prefix'] ?? null) ? $query['prefix'] : '');
        }

        return match ($method) {
            'PUT' => $this->put($key, (string) $request->getBody()),
            'DELETE' => $this->delete($key),
            default => isset($this->objects[$key])
                ? new Response(200, ['Content-Length' => (string) strlen($this->objects[$key]), 'ETag' => '"' . md5($this->objects[$key]) . '"', 'Last-Modified' => 'Mon, 23 Nov 2026 10:00:00 GMT'], $method === 'HEAD' ? '' : $this->objects[$key])
                : new Response(404, ['Content-Type' => 'application/xml'], $method === 'HEAD' ? '' : '<?xml version="1.0"?><Error><Code>NoSuchKey</Code></Error>'),
        };
    }

    private function put(string $key, string $body): Response
    {
        $this->objects[$key] = $this->corruptWrites ?? $body;

        return new Response(200, ['ETag' => '"' . md5($body) . '"']);
    }

    private function delete(string $key): Response
    {
        unset($this->objects[$key]);

        return new Response(204);
    }

    private function listing(string $prefix): Response
    {
        $contents = '';
        foreach ($this->objects as $key => $body) {
            if (str_starts_with($key, $prefix)) {
                $contents .= sprintf('<Contents><Key>%s</Key><Size>%d</Size><LastModified>2026-11-23T10:00:00.000Z</LastModified><ETag>"x"</ETag></Contents>', htmlspecialchars($key), strlen($body));
            }
        }

        return new Response(200, ['Content-Type' => 'application/xml'], '<?xml version="1.0"?><ListBucketResult><IsTruncated>false</IsTruncated>' . $contents . '</ListBucketResult>');
    }
}
