<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use Closure;
use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ServiceDefinition;
use SightPress\Module\Registrar\ServiceRegistrar;

/**
 * Records a module's services; the kernel adds them to the PHP-DI container.
 */
final readonly class ServiceCollector implements ServiceRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function factory(string $id, Closure $factory, bool $public = false): void
    {
        $this->registry->addService($this->module, ServiceDefinition::factory($this->id($id), $factory, $public));
    }

    public function autowire(string $class, ?string $id = null, bool $public = false): void
    {
        if (!class_exists($class)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers service class %s, which does not exist.',
                $this->module,
                $class,
            ));
        }

        $this->registry->addService($this->module, ServiceDefinition::autowire($this->id($id ?? $class), $class, $public));
    }

    /**
     * @return non-empty-string
     */
    private function id(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            throw new ModuleException(sprintf('Module "%s" registers a service without an id.', $this->module));
        }

        return $id;
    }
}
