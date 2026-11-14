<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Console\Command;
use SightPress\Module\Registrar\CommandRegistrar;

/**
 * Records a module's console commands.
 */
final readonly class CommandCollector implements CommandRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function add(string $command): void
    {
        if (!is_subclass_of($command, Command::class)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers command %s, which is not a class that implements %s.',
                $this->module,
                $command,
                Command::class,
            ));
        }

        $this->registry->addCommand($this->module, $command);
    }
}
