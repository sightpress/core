<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use SightPress\Core\Infrastructure\Crypto\SecretBox;

/**
 * Where a site keeps its files in an S3-compatible bucket (AWS S3, Cloudflare R2, DigitalOcean Spaces, MinIO). Both
 * keys stay encrypted here ({@see SecretBox}). The prefix is required: it is what keeps sites sharing a bucket apart,
 * and what `site:purge` deletes, so it can never be the whole bucket.
 */
final readonly class SiteS3Storage
{
    private const string BUCKET_PATTERN = '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/';

    private const string REGION_PATTERN = '/^[a-z0-9-]{2,32}$/i';

    private const string PREFIX_PATTERN = '/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*$/';

    private const array KEYS = ['bucket', 'region', 'endpoint', 'prefix', 'path_style', 'access_key', 'secret_key'];

    public function __construct(
        public string $bucket,
        public string $region,
        public ?string $endpoint,
        public string $prefix,
        public bool $pathStyle,
        public string $accessKey,
        public string $secretKey,
    ) {
        if (preg_match(self::BUCKET_PATTERN, $bucket) !== 1) {
            throw new SiteRegistryException('"storage.bucket" is not a valid bucket name.');
        }
        if (preg_match(self::REGION_PATTERN, $region) !== 1) {
            throw new SiteRegistryException('"storage.region" is not a valid region.');
        }
        if ($endpoint !== null && !self::isEndpoint($endpoint)) {
            throw new SiteRegistryException('"storage.endpoint" must be an http or https address with no path.');
        }
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1 || array_intersect(explode('/', $prefix), ['.', '..']) !== []) {
            throw new SiteRegistryException('"storage.prefix" must be a folder path inside the bucket, such as sites/my-site.');
        }
        if (!SecretBox::looksEncrypted($accessKey) || !SecretBox::looksEncrypted($secretKey)) {
            throw new SiteRegistryException('"storage.access_key" and "storage.secret_key" must be encrypted; set them with site:storage:s3.');
        }
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new SiteRegistryException(sprintf('unknown key "storage.s3.%s".', $key));
            }
        }
        $endpoint = $data['endpoint'] ?? null;
        $pathStyle = $data['path_style'] ?? false;
        if (($endpoint !== null && !is_string($endpoint)) || !is_bool($pathStyle)) {
            throw new SiteRegistryException('"storage.s3" has a value of the wrong type.');
        }

        return new self(
            self::text($data, 'bucket'),
            self::text($data, 'region'),
            $endpoint,
            self::text($data, 'prefix'),
            $pathStyle,
            self::text($data, 'access_key'),
            self::text($data, 'secret_key'),
        );
    }

    /**
     * Whether both point at the same files, or one's folder holds the other's: purging one would delete the other.
     */
    public function overlaps(self $other): bool
    {
        return $this->bucket === $other->bucket
            && $this->endpoint === $other->endpoint
            && ($this->prefix === $other->prefix
                || str_starts_with($this->prefix, $other->prefix . '/')
                || str_starts_with($other->prefix, $this->prefix . '/'));
    }

    /**
     * @return array<string, string|bool>
     */
    public function toArray(): array
    {
        return array_filter([
            'bucket' => $this->bucket,
            'region' => $this->region,
            'endpoint' => $this->endpoint,
            'prefix' => $this->prefix,
            'path_style' => $this->pathStyle,
            'access_key' => $this->accessKey,
            'secret_key' => $this->secretKey,
        ], static fn (string|bool|null $value): bool => $value !== null && $value !== false);
    }

    private static function isEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        return is_array($parts)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true);
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new SiteRegistryException(sprintf('"storage.s3.%s" must be a string.', $key));
        }

        return $value;
    }
}
