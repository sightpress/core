<?php

declare(strict_types=1);

namespace SightPress\Core\Cli;

use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a module's {@see Command} in the `bin/sightpress` console: declares its parameters and refuses to run when a
 * required option is missing.
 */
final class ModuleCommand extends ConsoleCommand
{
    /** @var list<CommandParameter> */
    private readonly array $parameters;

    public function __construct(private readonly Command $command)
    {
        parent::__construct($command->name());
        $this->setDescription($command->description());

        $this->parameters = $command->parameters();
        foreach ($this->parameters as $parameter) {
            match ($parameter->type) {
                CommandParameterType::Argument => $this->addArgument(
                    $parameter->name,
                    $parameter->required ? InputArgument::REQUIRED : InputArgument::OPTIONAL,
                    $parameter->description,
                ),
                CommandParameterType::Option => $this->addOption(
                    $parameter->name,
                    null,
                    InputOption::VALUE_REQUIRED,
                    $parameter->description . ($parameter->required ? ' (required)' : ''),
                ),
                CommandParameterType::Flag => $this->addOption(
                    $parameter->name,
                    null,
                    InputOption::VALUE_NONE,
                    $parameter->description,
                ),
            };
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $commandOutput = new ConsoleCommandOutput($output);
        $commandInput = new ConsoleCommandInput($input);

        foreach ($this->parameters as $parameter) {
            if (
                $parameter->type === CommandParameterType::Option
                && $parameter->required
                && ($commandInput->option($parameter->name) ?? '') === ''
            ) {
                $commandOutput->error(sprintf('The --%s option is required.', $parameter->name));

                return Command::INVALID;
            }
        }

        return $this->command->run($commandInput, $commandOutput);
    }
}
