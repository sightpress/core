<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Assistant;

use LogicException;
use Psr\Container\ContainerInterface;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Assistant\AssistantProvider;
use SightPress\Module\Assistant\AssistantTask;

/**
 * What the site's modules added to the AI assistant (`ModuleRegistrar::assistant()`): providers and tasks, by id,
 * built from the site's container when first needed. Shared code, so the assistant module can use them without
 * reaching into the module loader (ADR-0009).
 */
final class AssistantExtensions
{
    /** @var array<non-empty-string, AssistantProvider>|null */
    private ?array $providers = null;

    /** @var array<non-empty-string, AssistantTask>|null */
    private ?array $tasks = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ModuleRegistry $registry,
    ) {
    }

    /**
     * @return array<non-empty-string, AssistantProvider> by id, in registration order
     *
     * @throws LogicException when the container builds something else, or two providers have the same id
     */
    public function providers(): array
    {
        if ($this->providers === null) {
            $providers = [];
            foreach ($this->registry->assistantProviders() as $registered) {
                $provider = $this->container->get($registered->item);
                if (!$provider instanceof AssistantProvider) {
                    throw new LogicException(sprintf('Assistant provider %s of module "%s" is not a %s.', $registered->item, $registered->module, AssistantProvider::class));
                }
                $id = $provider->id();
                if (isset($providers[$id])) {
                    throw new LogicException(sprintf('Assistant provider %s of module "%s" uses the id "%s", which %s already uses.', $registered->item, $registered->module, $id, $providers[$id]::class));
                }
                $providers[$id] = $provider;
            }
            $this->providers = $providers;
        }

        return $this->providers;
    }

    public function provider(string $id): ?AssistantProvider
    {
        return $this->providers()[$id] ?? null;
    }

    /**
     * @return array<non-empty-string, AssistantTask> by id, in registration order
     *
     * @throws LogicException when the container builds something else, or two tasks have the same id
     */
    public function tasks(): array
    {
        if ($this->tasks === null) {
            $tasks = [];
            foreach ($this->registry->assistantTasks() as $registered) {
                $task = $this->container->get($registered->item);
                if (!$task instanceof AssistantTask) {
                    throw new LogicException(sprintf('Assistant task %s of module "%s" is not a %s.', $registered->item, $registered->module, AssistantTask::class));
                }
                $id = $task->id();
                if (isset($tasks[$id])) {
                    throw new LogicException(sprintf('Assistant task %s of module "%s" uses the id "%s", which %s already uses.', $registered->item, $registered->module, $id, $tasks[$id]::class));
                }
                $tasks[$id] = $task;
            }
            $this->tasks = $tasks;
        }

        return $this->tasks;
    }

    public function task(string $id): ?AssistantTask
    {
        return $this->tasks()[$id] ?? null;
    }
}
