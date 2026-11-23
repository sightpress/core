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
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a module's {@see Command} in the `bin/sightpress` console: declares its parameters, asks its questions when
 * someone is at the terminal ({@see Questions}), then checks every value ({@see ParameterValues}) and refuses to run
 * when one is missing or not accepted. With `--no-interaction`, or when the input is not a terminal, it never asks.
 */
final class ModuleCommand extends ConsoleCommand
{
    /** @var list<CommandParameter> */
    private readonly array $parameters;

    /** Whether the questions stopped the command (an answer was refused too often). */
    private bool $stopped = false;

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
        if ($command instanceof TakesPaths) {
            $this->addArgument(TakesPaths::ARGUMENT, InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Files or folders.');
        }
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $this->stopped = self::atTerminal($input) && !new Questions($this->parameters, $input, $output)->ask();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $commandOutput = new ConsoleCommandOutput($output);
        if ($this->stopped) {
            $this->stopped = false;

            return Command::INVALID;
        }

        $problem = new ParameterValues($input)->settle($this->parameters);
        if ($problem !== null) {
            $commandOutput->error($problem);

            return Command::INVALID;
        }

        return $this->command->run(new ConsoleCommandInput($input), $commandOutput);
    }

    /**
     * Whether someone can answer: the input reads from a terminal (or from a stream a test gave it).
     */
    private static function atTerminal(InputInterface $input): bool
    {
        if ($input instanceof StreamableInputInterface && $input->getStream() !== null) {
            return true;
        }

        return defined('STDIN') && stream_isatty(STDIN);
    }
}
