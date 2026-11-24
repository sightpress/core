<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Registrar\SmsRegistrar;
use SightPress\Module\Sms\SmsProvider;

/**
 * Collects a module's text message providers into the {@see ModuleRegistry}, tied to the module.
 */
final readonly class SmsCollector implements SmsRegistrar
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
        if (!is_subclass_of($provider, SmsProvider::class)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers text message provider %s, which is not a class that implements %s.',
                $this->module,
                $provider,
                SmsProvider::class,
            ));
        }

        $this->registry->addSmsProvider($this->module, $provider);
    }
}
