<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * Where a site keeps its files (media, avatars and branding). With the `local` adapter and no path, files go to the
 * site's folder under the tenant's `sites_dir`, named after the site id so it stays the same if the slug changes. With
 * `s3` they are in the bucket of {@see SiteS3Storage}. A `local` site can already hold S3 settings: it keeps using its
 * folder until `site:storage:migrate` has copied and checked every file.
 */
final readonly class SiteStorage
{
    public const array ADAPTERS = ['local', 's3'];

    /** @var non-empty-string */
    public string $adapter;

    /** @var non-empty-string|null */
    public ?string $path;

    /**
     * @param string|null $path absolute folder for the site's files; null for the default
     */
    public function __construct(string $adapter = 'local', ?string $path = null, public ?SiteS3Storage $s3 = null)
    {
        if ($adapter === '' || !in_array($adapter, self::ADAPTERS, true)) {
            throw new SiteRegistryException('"storage.adapter" is not valid: use local or s3.');
        }
        if ($adapter === 's3' && $s3 === null) {
            throw new SiteRegistryException('"storage.s3" is missing: the s3 adapter needs its bucket settings.');
        }
        if ($path !== null && ($path === '' || !str_starts_with($path, '/'))) {
            throw new SiteRegistryException('"storage.path" must be an absolute path.');
        }
        $this->adapter = $adapter;
        $this->path = $path;
    }

    public function isCloud(): bool
    {
        return $this->adapter === 's3';
    }

    public function withS3(SiteS3Storage $s3): self
    {
        return new self($this->adapter, $this->path, $s3);
    }

    /**
     * The same settings, now reading and writing the bucket.
     */
    public function switchedToS3(): self
    {
        return new self('s3', $this->path, $this->s3);
    }

    /**
     * The folder the site's files live in: the configured path, or `<sites_dir>/<site id>`.
     *
     * @return non-empty-string
     */
    public function root(string $sitesDir, string $siteId): string
    {
        return $this->path ?? rtrim($sitesDir, '/') . '/' . $siteId;
    }
}
