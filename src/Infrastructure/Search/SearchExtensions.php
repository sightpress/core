<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Search;

use LogicException;
use Psr\Container\ContainerInterface;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Search\SearchBackend;
use SightPress\Module\Search\SearchDocumentProvider;

/**
 * What the site's modules added to site search (`ModuleRegistrar::search()`): the providers of search documents and
 * the back-end that replaces core's built-in one, built from the site's container when first needed. Shared code,
 * so the search module can use them without reaching into the module loader (ADR-0009).
 */
final class SearchExtensions
{
    /** @var array<non-empty-string, SearchDocumentProvider>|null */
    private ?array $providers = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ModuleRegistry $registry,
    ) {
    }

    /**
     * Every provider of the modules that are on, by source, in registration order.
     *
     * @return array<non-empty-string, SearchDocumentProvider>
     *
     * @throws LogicException when the container builds something else, or two providers use the same source
     */
    public function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }
        $providers = [];
        foreach ($this->registry->searchProviders() as $registered) {
            $provider = $this->container->get($registered->item);
            if (!$provider instanceof SearchDocumentProvider) {
                throw new LogicException(sprintf('Search document provider %s of module "%s" is not a %s.', $registered->item, $registered->module, SearchDocumentProvider::class));
            }
            $source = $provider->source();
            if (isset($providers[$source])) {
                throw new LogicException(sprintf('Search document provider %s of module "%s" uses the source "%s", which %s already uses.', $registered->item, $registered->module, $source, $providers[$source]::class));
            }
            $providers[$source] = $provider;
        }

        return $this->providers = $providers;
    }

    public function provider(string $source): ?SearchDocumentProvider
    {
        return $this->providers()[$source] ?? null;
    }

    /**
     * The back-end a module put in place of core's built-in one, or null to use core's.
     *
     * @throws LogicException when the container builds something else
     */
    public function backend(): ?SearchBackend
    {
        $registered = $this->registry->searchBackend();
        if ($registered === null) {
            return null;
        }
        $backend = $this->container->get($registered->item);
        if (!$backend instanceof SearchBackend) {
            throw new LogicException(sprintf('Search back-end %s of module "%s" is not a %s.', $registered->item, $registered->module, SearchBackend::class));
        }

        return $backend;
    }
}
