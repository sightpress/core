<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use Closure;

/**
 * Moves `config/sites.php` from an older format version to {@see SiteRegistryFormat::VERSION}, one version at a
 * time (`site:registry:upgrade`, ADR-0002). The result is validated before it is written, and the old file is
 * kept as `<file>.bak`.
 */
final readonly class SiteRegistryUpgrader
{
    /**
     * @param array<int, Closure(array<mixed>): array<mixed>>|null $steps keyed by the version each step upgrades from;
     *                                                                 {@see SiteRegistryFormat::upgradeSteps()} by default
     * @param positive-int                                    $target   the version to upgrade to
     * @param (Closure(array<mixed>, string): mixed)|null      $validate checks the result before it is written;
     *                                                                   {@see SiteRegistryFormat::parse()} by default
     */
    public function __construct(
        private SiteRegistryFile $file,
        private ?array $steps = null,
        private int $target = SiteRegistryFormat::VERSION,
        private ?Closure $validate = null,
    ) {
    }

    /**
     * @return array{positive-int, positive-int} the version before and after
     */
    public function upgrade(): array
    {
        $versions = [$this->target, $this->target];
        $this->file->update(function (mixed $registry) use (&$versions): ?array {
            if (!is_array($registry)) {
                throw new SiteRegistryException(sprintf('%s must return an array.', $this->file->path));
            }

            $version = SiteRegistryFormat::version($registry, $this->file->path);
            $versions = [$version, $this->target];
            if ($version > $this->target) {
                throw new SiteRegistryException(sprintf(
                    '%s uses format version %d, made by a newer SightPress; this version reads up to %d. '
                    . 'Upgrade SightPress.',
                    $this->file->path,
                    $version,
                    $this->target,
                ));
            }
            if ($version === $this->target) {
                return null;
            }

            for (; $version < $this->target; $version++) {
                $step = ($this->steps ?? SiteRegistryFormat::upgradeSteps())[$version] ?? throw new SiteRegistryException(sprintf(
                    'There is no upgrade of %s from format version %d.',
                    $this->file->path,
                    $version,
                ));
                $registry = $step($registry);
                $registry['version'] = $version + 1;
            }
            ($this->validate ?? SiteRegistryFormat::parse(...))($registry, $this->file->path);

            return $registry;
        });

        return $versions;
    }
}
