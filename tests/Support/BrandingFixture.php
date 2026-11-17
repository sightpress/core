<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SightPress\Core\Branding\BrandImages;
use SightPress\Core\Branding\BrandingService;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tenant\Site;

/**
 * Branding for tests: a migrated site database in memory for the settings, the site's storage in a temporary
 * folder, and the service on top.
 */
final class BrandingFixture
{
    public readonly CoreSiteDatabase $database;

    public readonly TemporaryDirectory $directory;

    public readonly Filesystem $storage;

    public readonly Site $site;

    /**
     * @param non-empty-list<string> $locales the first one is the default
     */
    public function __construct(string $name = 'Riverside Library', OwnerType $owner = OwnerType::Organization, array $locales = ['en-GB'])
    {
        $this->database = new CoreSiteDatabase();
        $this->directory = new TemporaryDirectory();
        $this->storage = new Filesystem(new LocalFilesystemAdapter($this->directory->path));
        $base = SiteFixture::site();
        $this->site = new Site($base->id, $base->slug, $name, $base->status, $owner, $base->domains, $base->databaseDsn, $base->storage, $locales[0], $locales, 'Europe/London', $base->createdAt);
    }

    public function service(): BrandingService
    {
        return new BrandingService($this->storage, $this->database->settings(), $this->site, new BrandImages());
    }

    public function remove(): void
    {
        $this->directory->remove();
    }

    /**
     * An SVG of the given size, with a shape in it.
     */
    public static function svg(int $width = 300, int $height = 100): string
    {
        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d"><rect width="%1$d" height="%2$d" fill="#123456"/></svg>', $width, $height);
    }
}
