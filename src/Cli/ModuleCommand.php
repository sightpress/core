<?php

declare(strict_types=1);

namespace SightPress\Core\Cli;

use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Runs a module's {@see Command} in the `bin/sightpress` console: declares its parameters, asks for the required ones
 * that are missing when someone is at the terminal (the question is the parameter's description), and refuses to
 * run when a required option is still missing. With `--no-interaction`, or when the input is not a terminal, it
 * never asks.
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

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if (!self::atTerminal($input)) {
            return;
        }

        $questions = new QuestionHelper();
        foreach ($this->parameters as $parameter) {
            if (!$parameter->required || $parameter->type === CommandParameterType::Flag) {
                continue;
            }
            $argument = $parameter->type === CommandParameterType::Argument;
            $current = $argument ? $input->getArgument($parameter->name) : $input->getOption($parameter->name);
            if (is_string($current) && trim($current) !== '') {
                continue;
            }

            $answer = $questions->ask($input, $output, new Question($parameter->description . "\n> "));
            $answer = is_string($answer) ? trim($answer) : '';
            if ($answer === '') {
                continue;
            }
            if ($argument) {
                $input->setArgument($parameter->name, $answer);
            } else {
                $input->setOption($parameter->name, $answer);
            }
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
                && trim($commandInput->option($parameter->name) ?? '') === ''
            ) {
                $commandOutput->error(sprintf('The --%s option is required.', $parameter->name));

                return Command::INVALID;
            }
        }

        return $this->command->run($commandInput, $commandOutput);
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
