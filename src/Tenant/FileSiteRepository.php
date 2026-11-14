<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * {@see SiteRepository} on top of `config/sites.php`. It reads and validates the file the first time a site is
 * needed, once per process.
 */
final class FileSiteRepository implements SiteRepository
{
    /** @var array<non-empty-string, Site>|null */
    private ?array $sites = null;

    /** @var array<string, Site> */
    private array $byDomain = [];

    public function __construct(private readonly SiteRegistryFile $file)
    {
    }

    public function all(): array
    {
        if ($this->sites === null) {
            $this->sites = SiteRegistryFormat::parse($this->file->read(), $this->file->path);
            $this->byDomain = [];
            foreach ($this->sites as $site) {
                foreach ($site->domains as $domain) {
                    $this->byDomain[$domain] = $site;
                }
            }
        }

        return $this->sites;
    }

    public function find(string $slug): ?Site
    {
        return $this->all()[$slug] ?? null;
    }

    public function findByDomain(string $domain): ?Site
    {
        $this->all();

        return $this->byDomain[rtrim(strtolower($domain), '.')] ?? null;
    }

    public function save(Site $site): void
    {
        $this->change(static function (array $sites) use ($site): array {
            $sites[$site->slug] = $site;

            return $sites;
        });
    }

    public function remove(string $slug): void
    {
        $this->change(static function (array $sites) use ($slug): array {
            unset($sites[$slug]);

            return $sites;
        });
    }

    /**
     * @param callable(array<non-empty-string, Site>): array<Site> $change
     */
    private function change(callable $change): void
    {
        $source = $this->file->path;
        $this->file->update(static function (mixed $registry) use ($change, $source): array {
            $exported = SiteRegistryFormat::export($change(SiteRegistryFormat::parse($registry, $source)));
            // Checks what only the whole registry can show, such as a domain used by two sites.
            SiteRegistryFormat::parse($exported, $source);

            return $exported;
        });
        $this->sites = null;
    }
}
