<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Registrar\SearchRegistrar;
use SightPress\Module\Search\SearchBackend;
use SightPress\Module\Search\SearchDocumentProvider;

/**
 * Records a module's search document providers and the search back-end it puts in place of core's.
 */
final readonly class SearchCollector implements SearchRegistrar
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
        if (!is_subclass_of($provider, SearchDocumentProvider::class)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers search document provider %s, which is not a class that implements %s.',
                $this->module,
                $provider,
                SearchDocumentProvider::class,
            ));
        }

        $this->registry->addSearchProvider($this->module, $provider);
    }

    public function useBackend(string $backend): void
    {
        if (!is_subclass_of($backend, SearchBackend::class)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers search back-end %s, which is not a class that implements %s.',
                $this->module,
                $backend,
                SearchBackend::class,
            ));
        }

        $this->registry->useSearchBackend($this->module, $backend);
    }
}
