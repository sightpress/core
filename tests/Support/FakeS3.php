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

    /** @var array<string, array{key: string, parts: array<int, string>}> unfinished multipart uploads, by upload id */
    public array $uploads = [];

    /** @var array<string, string> fails only the operations named here: operation => S3 error code */
    public array $failing = [];

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
        $multipart = $this->multipart($request, $method, $key, $query);
        if ($multipart !== null) {
            return $multipart;
        }
        if ($method === 'PUT' && $request->hasHeader('x-amz-copy-source')) {
            $source = rawurldecode((string) preg_replace('#^/?[^/]+/#', '', explode('?', $request->getHeaderLine('x-amz-copy-source'))[0]));
            if (isset($this->failing['CopyObject']) || !isset($this->objects[$source])) {
                return self::error($this->failing['CopyObject'] ?? 'NoSuchKey', 404);
            }
            $this->objects[$key] = $this->objects[$source];

            return new Response(200, ['Content-Type' => 'application/xml'], '<?xml version="1.0"?><CopyObjectResult><ETag>"x"</ETag></CopyObjectResult>');
        }
        if ($method === 'GET' && $request->hasHeader('Range') && isset($this->objects[$key]) && preg_match('/bytes=(\d+)-(\d+)/', $request->getHeaderLine('Range'), $range) === 1) {
            return new Response(206, [], substr($this->objects[$key], (int) $range[1], (int) $range[2] - (int) $range[1] + 1));
        }
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

    /**
     * @param array<array-key, mixed> $query
     */
    private function multipart(RequestInterface $request, string $method, string $key, array $query): ?Response
    {
        if (array_key_exists('uploads', $query)) {
            if ($method === 'GET') {
                $items = '';
                foreach ($this->uploads as $id => $upload) {
                    $items .= sprintf('<Upload><Key>%s</Key><UploadId>%s</UploadId></Upload>', htmlspecialchars($upload['key']), $id);
                }

                return new Response(200, ['Content-Type' => 'application/xml'], '<?xml version="1.0"?><ListMultipartUploadsResult><IsTruncated>false</IsTruncated>' . $items . '</ListMultipartUploadsResult>');
            }
            if (isset($this->failing['CreateMultipartUpload'])) {
                return self::error($this->failing['CreateMultipartUpload'], 403);
            }
            $id = 'upload-' . (count($this->uploads) + 1) . '-' . md5($key);
            $this->uploads[$id] = ['key' => $key, 'parts' => []];

            return new Response(200, ['Content-Type' => 'application/xml'], sprintf('<?xml version="1.0"?><InitiateMultipartUploadResult><UploadId>%s</UploadId></InitiateMultipartUploadResult>', $id));
        }
        $id = $query['uploadId'] ?? null;
        if (!is_string($id)) {
            return null;
        }
        $operation = match ($method) {
            'DELETE' => 'AbortMultipartUpload',
            'POST' => 'CompleteMultipartUpload',
            default => 'ListParts',
        };
        if (isset($this->failing[$operation])) {
            return self::error($this->failing[$operation], 403);
        }
        if (!isset($this->uploads[$id])) {
            return self::error('NoSuchUpload', 404);
        }
        if ($method === 'DELETE') {
            unset($this->uploads[$id]);

            return new Response(204);
        }
        if ($method === 'POST') {
            ksort($this->uploads[$id]['parts']);
            $this->objects[$key] = implode('', $this->uploads[$id]['parts']);
            unset($this->uploads[$id]);

            return new Response(200, ['Content-Type' => 'application/xml'], '<?xml version="1.0"?><CompleteMultipartUploadResult><ETag>"x"</ETag></CompleteMultipartUploadResult>');
        }
        $items = '';
        foreach ($this->uploads[$id]['parts'] as $number => $body) {
            $items .= sprintf('<Part><PartNumber>%d</PartNumber><ETag>"%s"</ETag><Size>%d</Size></Part>', $number, md5($body), strlen($body));
        }

        return new Response(200, ['Content-Type' => 'application/xml'], '<?xml version="1.0"?><ListPartsResult><IsTruncated>false</IsTruncated>' . $items . '</ListPartsResult>');
    }

    private static function error(string $code, int $status): Response
    {
        return new Response($status, ['Content-Type' => 'application/xml'], sprintf('<?xml version="1.0"?><Error><Code>%s</Code></Error>', $code));
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
