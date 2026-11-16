<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * Where a site keeps its files (media, and later avatars and branding). This version has the `local` adapter only;
 * without a path, files go to the site's folder under the tenant's `sites_dir`, named after the site id so it stays
 * the same if the slug changes.
 */
final readonly class SiteStorage
{
    public const array ADAPTERS = ['local'];

    /** @var non-empty-string */
    public string $adapter;

    /** @var non-empty-string|null */
    public ?string $path;

    /**
     * @param string|null $path absolute folder for the site's files; null for the default
     */
    public function __construct(string $adapter = 'local', ?string $path = null)
    {
        if ($adapter === '' || !in_array($adapter, self::ADAPTERS, true)) {
            throw new SiteRegistryException('"storage.adapter" is not valid: use local.');
        }
        if ($path !== null && ($path === '' || !str_starts_with($path, '/'))) {
            throw new SiteRegistryException('"storage.path" must be an absolute path.');
        }
        $this->adapter = $adapter;
        $this->path = $path;
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
