<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Seo;

use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Seo\SeoEntry;
use SightPress\Module\Seo\SeoProvider;
use Throwable;

/**
 * The public pages the site's modules list for the sitemap and `llms.txt` (`ModuleRegistrar::seo()`, ADR-0060),
 * built from the site's container when first needed. Only modules that are on for the site take part. Shared code,
 * so the SEO module can use them without reaching into the module loader (ADR-0009).
 *
 * A provider that fails is left out of the files and written to the log, so one module can't break the sitemap.
 */
final class SeoExtensions
{
    /** @var list<array{string, SeoProvider}>|null module id and provider */
    private ?array $providers = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ModuleRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function hasProviders(): bool
    {
        return $this->registry->seoProviders() !== [];
    }

    /**
     * Every module page in one language, in registration order.
     *
     * @return list<SeoEntry>
     *
     * @throws LogicException when the container builds something that isn't a provider
     */
    public function entries(string $locale): array
    {
        $entries = [];
        foreach ($this->providers() as [$module, $provider]) {
            try {
                foreach ($provider->entries($locale) as $entry) {
                    $entries[] = $entry;
                }
            } catch (Throwable $exception) {
                $this->logger->error('SEO provider {provider} of module "{module}" failed, so its pages were left out of the sitemap and llms.txt: {reason}', [
                    'provider' => $provider::class,
                    'module' => $module,
                    'reason' => $exception->getMessage(),
                    'exception' => $exception,
                ]);
            }
        }

        return $entries;
    }

    /**
     * @return list<array{string, SeoProvider}> module id and provider
     */
    private function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }
        $providers = [];
        foreach ($this->registry->seoProviders() as $registered) {
            $provider = $this->container->get($registered->item);
            if (!$provider instanceof SeoProvider) {
                throw new LogicException(sprintf('SEO provider %s of module "%s" is not a %s.', $registered->item, $registered->module, SeoProvider::class));
            }
            $providers[] = [$registered->module, $provider];
        }

        return $this->providers = $providers;
    }
}
