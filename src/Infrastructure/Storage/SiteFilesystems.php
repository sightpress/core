<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Storage;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Closure;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Crypto\SecretBoxException;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteS3Storage;
use Throwable;

/**
 * Opens a site's file storage: its local folder or its S3-compatible bucket. The S3 packages are optional, so a site
 * set up for S3 on an install without them gets a message saying what to install.
 */
final readonly class SiteFilesystems
{
    private const string CHECK_FILE = '.sightpress-storage-check';

    /**
     * @param (Closure(array<string, mixed>): S3Client)|null $clients builds the S3 client (tests pass a mocked one)
     * @param list<string>                                  $packages classes the optional S3 packages provide
     */
    public function __construct(
        private SecretBox $secrets,
        private string $sitesDir,
        private ?Closure $clients = null,
        private array $packages = [AwsS3V3Adapter::class, S3Client::class],
    ) {
    }

    public function forSite(Site $site): FilesystemOperator
    {
        return $site->storage->isCloud() && $site->storage->s3 !== null
            ? $this->bucket($site->storage->s3)
            : $this->local($site);
    }

    public function local(Site $site): FilesystemOperator
    {
        return new Filesystem(new LocalFilesystemAdapter($site->storage->root($this->sitesDir, $site->id), lazyRootCreation: true));
    }

    public function bucket(SiteS3Storage $s3): FilesystemOperator
    {
        if (array_filter($this->packages, static fn (string $class): bool => !class_exists($class)) !== []) {
            throw new StorageException('Install league/flysystem-aws-s3-v3 to keep files in S3.');
        }
        try {
            $credentials = ['key' => $this->secrets->decrypt($s3->accessKey), 'secret' => $this->secrets->decrypt($s3->secretKey)];
        } catch (SecretBoxException $exception) {
            throw new StorageException('The storage keys can\'t be read with this APP_SECRET. Set them again with site:storage:s3.', previous: $exception);
        }
        $options = [
            'version' => '2006-03-01',
            'region' => $s3->region,
            'credentials' => $credentials,
            'use_path_style_endpoint' => $s3->pathStyle,
            'http' => ['connect_timeout' => 10],
        ];
        if ($s3->endpoint !== null) {
            $options['endpoint'] = $s3->endpoint;
        }
        $client = $this->clients === null ? new S3Client($options) : ($this->clients)($options);

        return new Filesystem(new AwsS3V3Adapter($client, $s3->bucket, $s3->prefix));
    }

    /**
     * Writes, reads back and deletes a small file, so wrong settings are found before anything depends on them.
     *
     * @throws StorageException in plain language, never with a key
     */
    public function check(SiteS3Storage $s3): void
    {
        $storage = $this->bucket($s3);
        $probe = bin2hex(random_bytes(16));
        try {
            $storage->write(self::CHECK_FILE, $probe);
            $matches = $storage->read(self::CHECK_FILE) === $probe;
            $storage->delete(self::CHECK_FILE);
        } catch (FilesystemException $exception) {
            throw new StorageException(self::explain($exception, $s3), previous: null);
        }
        if (!$matches) {
            throw new StorageException(sprintf('The bucket "%s" didn\'t give back the file that was written to it.', $s3->bucket));
        }
    }

    /**
     * What went wrong, from the provider's error code. The provider's own message is left out: it can quote the key id.
     */
    public static function explain(Throwable $exception, SiteS3Storage $s3): string
    {
        $cause = $exception;
        while ($cause !== null && !$cause instanceof AwsException) {
            $cause = $cause->getPrevious();
        }
        $code = $cause instanceof AwsException ? (string) $cause->getAwsErrorCode() : '';

        return match ($code) {
            'InvalidAccessKeyId', 'SignatureDoesNotMatch', 'InvalidSecurity', 'InvalidToken', 'ExpiredToken' => 'The storage provider refused the access key or secret key. Check both and try again.',
            'AccessDenied', 'AllAccessDisabled' => sprintf('These keys aren\'t allowed to use the bucket "%s". Give them read, write, list and delete access.', $s3->bucket),
            'NoSuchBucket' => sprintf('There is no bucket named "%s". Check the name and the region.', $s3->bucket),
            'PermanentRedirect', 'AuthorizationHeaderMalformed', 'IllegalLocationConstraintException' => sprintf('The bucket "%s" is in another region. Check the region.', $s3->bucket),
            '' => 'The storage provider can\'t be reached. Check the address and the network.',
            default => sprintf('The storage provider refused the request (%s).', preg_replace('/[^A-Za-z0-9]/', '', $code)),
        };
    }
}
