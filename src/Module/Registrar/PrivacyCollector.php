<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Privacy\ConsentPurpose;
use SightPress\Module\Privacy\CookieDeclaration;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\ProcessorSource;
use SightPress\Module\Registrar\PrivacyRegistrar;

/**
 * Collects a module's personal data providers, cookies, processor sources and consent purposes into the
 * {@see ModuleRegistry}, tied to the module (ADR-0077). The privacy module (GD-1) reads them from there.
 */
final readonly class PrivacyCollector implements PrivacyRegistrar
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
        $this->check($provider, PersonalDataProvider::class, 'personal data provider');
        $this->registry->addPersonalDataProvider($this->module, $provider);
    }

    public function addCookie(CookieDeclaration $cookie): void
    {
        $this->registry->addCookie($this->module, $cookie);
    }

    public function addProcessors(string $source): void
    {
        $this->check($source, ProcessorSource::class, 'processor source');
        $this->registry->addProcessorSource($this->module, $source);
    }

    public function addConsentPurpose(ConsentPurpose $purpose): void
    {
        $this->registry->addConsentPurpose($this->module, $purpose);
    }

    /**
     * @param class-string $interface
     */
    private function check(string $class, string $interface, string $what): void
    {
        if (!is_subclass_of($class, $interface)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers %s %s, which is not a class that implements %s.',
                $this->module,
                $what,
                $class,
                $interface,
            ));
        }
    }
}
