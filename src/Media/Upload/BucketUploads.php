<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Upload;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use SightPress\Core\Infrastructure\Storage\SiteFilesystems;
use SightPress\Core\Infrastructure\Storage\StorageException;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteS3Storage;
use Stringable;

/**
 * S3 multipart uploads in the site's bucket. Files arrive under `<prefix>/incoming/<session id>`, which the bucket's
 * lifecycle rule also expires. Each part's address is signed for one part number of one upload, for 15 minutes. S3
 * can't sign a presigned request's size, so {@see DirectUploads} checks every part's size before joining them, and a
 * part of the wrong size is never used. Provider errors become a {@see StorageException} that never quotes a key.
 */
final readonly class BucketUploads
{
    public const string URL_LIFETIME = '+15 minutes';

    private const int READ_BYTES = 1024 * 1024;

    public function __construct(private Site $site, private SiteFilesystems $filesystems)
    {
    }

    public function isCloud(): bool
    {
        return $this->site->storage->isCloud() && $this->site->storage->s3 !== null;
    }

    public function s3(): SiteS3Storage
    {
        return $this->site->storage->s3 ?? throw new StorageException('This site has no bucket.');
    }

    public function start(string $id): string
    {
        return $this->call(fn (S3Client $client): string => self::text($client->createMultipartUpload($this->target($id))['UploadId']));
    }

    public function partUrl(UploadSession $session, int $number): string
    {
        return $this->call(function (S3Client $client) use ($session, $number): string {
            $command = $client->getCommand('UploadPart', $this->target($session->id) + [
                'UploadId' => (string) $session->remoteId,
                'PartNumber' => $number,
            ]);

            return (string) $client->createPresignedRequest($command, self::URL_LIFETIME)->getUri();
        });
    }

    /**
     * The parts the bucket holds.
     *
     * @return array<int, array{size: int, etag: string}> by part number
     */
    public function parts(UploadSession $session): array
    {
        return $this->call(function (S3Client $client) use ($session): array {
            $parts = [];
            foreach ($client->getPaginator('ListParts', $this->target($session->id) + ['UploadId' => (string) $session->remoteId]) as $page) {
                $listed = $page['Parts'] ?? [];
                foreach (is_array($listed) ? $listed : [] as $part) {
                    if (is_array($part)) {
                        $parts[self::number($part['PartNumber'] ?? 0)] = ['size' => self::number($part['Size'] ?? 0), 'etag' => trim(self::text($part['ETag'] ?? ''), '"')];
                    }
                }
            }

            return $parts;
        });
    }

    /**
     * @param array<int, string> $etags by part number
     */
    public function complete(UploadSession $session, array $etags): void
    {
        ksort($etags);
        $parts = [];
        foreach ($etags as $number => $etag) {
            $parts[] = ['PartNumber' => $number, 'ETag' => '"' . $etag . '"'];
        }
        $this->call(fn (S3Client $client): mixed => $client->completeMultipartUpload($this->target($session->id) + [
            'UploadId' => (string) $session->remoteId,
            'MultipartUpload' => ['Parts' => $parts],
        ]));
    }

    /**
     * Stops the upload and removes whatever it left. Doing it twice is harmless.
     */
    public function abort(UploadSession $session): void
    {
        $this->call(function (S3Client $client) use ($session): void {
            try {
                $client->abortMultipartUpload($this->target($session->id) + ['UploadId' => (string) $session->remoteId]);
            } catch (AwsException $exception) {
                if ($exception->getAwsErrorCode() !== 'NoSuchUpload') {
                    throw $exception;
                }
            }
            $client->deleteObject($this->target($session->id));
        });
    }

    public function file(UploadSession $session): IncomingFile
    {
        $target = $this->target($session->id);
        $path = 'incoming/' . $session->id;
        $uploads = $this;

        return new class ($uploads, $target, $path) implements IncomingFile {
            /**
             * @param array{Bucket: string, Key: string} $target
             */
            public function __construct(private readonly BucketUploads $uploads, private readonly array $target, private readonly string $path)
            {
            }

            public function size(): int
            {
                return $this->uploads->call(fn (S3Client $client): int => BucketUploads::number($client->headObject($this->target)['ContentLength']));
            }

            public function start(int $bytes): string
            {
                return $this->uploads->call(fn (S3Client $client): string => BucketUploads::text($client->getObject($this->target + ['Range' => 'bytes=0-' . ($bytes - 1)])['Body']));
            }

            public function sha256(): string
            {
                $hash = hash_init('sha256');
                $this->uploads->read($this->path, static function (string $data) use ($hash): void {
                    hash_update($hash, $data);
                });

                return hash_final($hash);
            }

            public function copyTo(string $path): void
            {
                $from = $this->path;
                $this->uploads->storage(static function (FilesystemOperator $storage) use ($from, $path): void {
                    $storage->copy($from, $path, ['retain_visibility' => false]);
                });
            }

            public function saveTo(string $localFile): void
            {
                $out = fopen($localFile, 'wb');
                if ($out === false) {
                    throw new StorageException('The file can\'t be fetched from storage.');
                }
                try {
                    $this->uploads->read($this->path, static function (string $data) use ($out): void {
                        fwrite($out, $data);
                    });
                } finally {
                    fclose($out);
                }
            }

            public function delete(): void
            {
                $this->uploads->call(fn (S3Client $client): mixed => $client->deleteObject($this->target));
            }
        };
    }

    /**
     * Streams a stored file through `$use`, a piece at a time.
     *
     * @param callable(string): void $use
     */
    public function read(string $path, callable $use): void
    {
        $this->storage(static function (FilesystemOperator $storage) use ($path, $use): void {
            $stream = $storage->readStream($path);
            try {
                while (($data = fread($stream, self::READ_BYTES)) !== false && $data !== '') {
                    $use($data);
                }
            } finally {
                fclose($stream);
            }
        });
    }

    /**
     * @param callable(FilesystemOperator): void $use
     */
    public function storage(callable $use): void
    {
        try {
            $use($this->filesystems->bucket($this->s3()));
        } catch (FilesystemException $exception) {
            throw new StorageException(SiteFilesystems::explain($exception, $this->s3()));
        }
    }

    /**
     * @template T
     *
     * @param callable(S3Client): T $use
     *
     * @return T
     */
    public function call(callable $use): mixed
    {
        try {
            return $use($this->filesystems->client($this->s3()));
        } catch (AwsException $exception) {
            throw new StorageException(SiteFilesystems::explain($exception, $this->s3()));
        }
    }

    public static function text(mixed $value): string
    {
        return is_scalar($value) || $value instanceof Stringable ? (string) $value : '';
    }

    public static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array{Bucket: string, Key: string}
     */
    private function target(string $id): array
    {
        $s3 = $this->s3();

        return ['Bucket' => $s3->bucket, 'Key' => $s3->prefix . '/incoming/' . $id];
    }
}
