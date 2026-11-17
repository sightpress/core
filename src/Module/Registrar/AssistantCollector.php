<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Assistant\AssistantProvider;
use SightPress\Module\Assistant\AssistantTask;
use SightPress\Module\Registrar\AssistantRegistrar;

/**
 * Records a module's AI assistant providers and tasks. Registering them contacts nobody.
 */
final readonly class AssistantCollector implements AssistantRegistrar
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
        if (!is_subclass_of($provider, AssistantProvider::class)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers assistant provider %s, which is not a class that implements %s.',
                $this->module,
                $provider,
                AssistantProvider::class,
            ));
        }

        $this->registry->addAssistantProvider($this->module, $provider);
    }

    public function addTask(string $task): void
    {
        if (!is_subclass_of($task, AssistantTask::class)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers assistant task %s, which is not a class that implements %s.',
                $this->module,
                $task,
                AssistantTask::class,
            ));
        }

        $this->registry->addAssistantTask($this->module, $task);
    }
}
