<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Registrar\SeoRegistrar;
use SightPress\Module\Seo\SeoProvider;

/**
 * Collects a module's SEO providers into the {@see ModuleRegistry}, tied to the module.
 */
final readonly class SeoCollector implements SeoRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function addProvider(string $provider): void
    {
        if (!is_subclass_of($provider, SeoProvider::class)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers SEO provider %s, which is not a class that implements %s.',
                $this->module,
                $provider,
                SeoProvider::class,
            ));
        }

        $this->registry->addSeoProvider($this->module, $provider);
    }
}
