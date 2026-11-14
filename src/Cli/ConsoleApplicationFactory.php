<?php

declare(strict_types=1);

namespace SightPress\Core\Cli;

use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use Symfony\Component\Console\Application;

/**
 * Builds the `bin/sightpress` console with every command the modules registered.
 */
final readonly class ConsoleApplicationFactory
{
    public function __construct(
        private ContainerInterface $container,
        private ModuleRegistry $registry,
    ) {
    }

    public function create(string $version = 'UNKNOWN'): Application
    {
        $application = new Application('SightPress', $version);

        $names = [];
        foreach ($this->registry->commands() as $registered) {
            $command = Services::get($this->container, $registered->item);
            $name = $command->name();
            if (isset($names[$name])) {
                throw new ModuleException(sprintf(
                    'Command "%s" is registered twice: by module "%s" and by module "%s".',
                    $name,
                    $names[$name],
                    $registered->module,
                ));
            }
            $names[$name] = $registered->module;
            $application->addCommand(new ModuleCommand($command));
        }

        return $application;
    }
}
