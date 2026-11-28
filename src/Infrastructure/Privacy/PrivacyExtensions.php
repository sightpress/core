<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Privacy;

use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SightPress\Core\Infrastructure\Http\OutboundHostSource;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Privacy\ConsentPurpose;
use SightPress\Module\Privacy\CookieDeclaration;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\ProcessorDeclaration;
use SightPress\Module\Privacy\ProcessorSource;
use Throwable;

/**
 * What the site's modules declare about personal data (`ModuleRegistrar::privacy()`, ADR-0077): their data sets,
 * cookies, third parties and consent purposes, built from the site's container when first needed. Only modules that
 * are on for the site take part. Shared code, so the privacy module and the outgoing HTTP client can use it without
 * reaching into the module loader (ADR-0009).
 *
 * Third parties are asked every time, so a feature that is set up or removed shows at once; one whose source fails
 * is left out (so its hosts are refused) and written to the log.
 */
final class PrivacyExtensions
{
    /** @var array<non-empty-string, array{string, PersonalDataProvider}>|null data set key => module id and provider */
    private ?array $providers = null;

    /** @var list<array{string, ProcessorSource}>|null */
    private ?array $sources = null;

    /**
     * @param list<OutboundHostSource> $hostSources core's services that call the operator's own hosts
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ModuleRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly array $hostSources = [],
    ) {
    }

    /**
     * @return array<non-empty-string, PersonalDataProvider> by data set key, in registration order
     *
     * @throws LogicException when the container builds something else, or two providers use the same key
     */
    public function providers(): array
    {
        return array_map(static fn (array $entry): PersonalDataProvider => $entry[1], $this->entries());
    }

    public function provider(string $key): ?PersonalDataProvider
    {
        return $this->entries()[$key][1] ?? null;
    }

    /**
     * The id of the module that keeps a data set.
     */
    public function moduleOf(string $key): ?string
    {
        return $this->entries()[$key][0] ?? null;
    }

    /**
     * @return list<CookieDeclaration>
     */
    public function cookies(): array
    {
        return array_map(static fn ($registered): CookieDeclaration => $registered->item, $this->registry->cookies());
    }

    /**
     * @return list<ConsentPurpose>
     */
    public function consentPurposes(): array
    {
        return array_map(static fn ($registered): ConsentPurpose => $registered->item, $this->registry->consentPurposes());
    }

    /**
     * The third parties the site sends personal data to right now, each id once (the first wins).
     *
     * @return array<non-empty-string, ProcessorDeclaration> by id
     */
    public function processors(): array
    {
        $processors = [];
        foreach ($this->sources() as [$module, $source]) {
            try {
                foreach ($source->processors() as $processor) {
                    $processors[$processor->id] ??= $processor;
                }
            } catch (Throwable $exception) {
                $this->logger->error('Processor source {source} of module "{module}" failed, so its third parties are left out and their addresses refused: {reason}', [
                    'source' => $source::class,
                    'module' => $module,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        return $processors;
    }

    /**
     * Every host the site may call: those of its third parties and of the operator's own services (core's processor
     * sources may name those too, by also being an {@see OutboundHostSource}).
     *
     * @return list<string>
     */
    public function outboundHosts(): array
    {
        $hosts = [];
        foreach ($this->processors() as $processor) {
            foreach ($processor->hosts as $host) {
                $hosts[] = $host;
            }
        }
        $sources = $this->hostSources;
        foreach ($this->sources() as [, $source]) {
            if ($source instanceof OutboundHostSource) {
                $sources[] = $source;
            }
        }
        foreach ($sources as $source) {
            foreach ($source->outboundHosts() as $host) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * @return array<non-empty-string, array{string, PersonalDataProvider}>
     */
    private function entries(): array
    {
        if ($this->providers === null) {
            $providers = [];
            foreach ($this->registry->personalDataProviders() as $registered) {
                $provider = $this->container->get($registered->item);
                if (!$provider instanceof PersonalDataProvider) {
                    throw new LogicException(sprintf('Personal data provider %s of module "%s" is not a %s.', $registered->item, $registered->module, PersonalDataProvider::class));
                }
                $key = $provider->describe()->key;
                if (isset($providers[$key])) {
                    throw new LogicException(sprintf('Personal data provider %s of module "%s" uses the data set key "%s", which %s already uses.', $registered->item, $registered->module, $key, $providers[$key][1]::class));
                }
                $providers[$key] = [$registered->module, $provider];
            }
            $this->providers = $providers;
        }

        return $this->providers;
    }

    /**
     * @return list<array{string, ProcessorSource}>
     */
    private function sources(): array
    {
        if ($this->sources === null) {
            $sources = [];
            foreach ($this->registry->processorSources() as $registered) {
                $source = $this->container->get($registered->item);
                if (!$source instanceof ProcessorSource) {
                    throw new LogicException(sprintf('Processor source %s of module "%s" is not a %s.', $registered->item, $registered->module, ProcessorSource::class));
                }
                $sources[] = [$registered->module, $source];
            }
            $this->sources = $sources;
        }

        return $this->sources;
    }
}
