<?php

declare(strict_types=1);

namespace SightPress\Core\Cli;

use SightPress\Module\Console\CommandInput;
use Symfony\Component\Console\Input\InputInterface;

/**
 * A module command's view of the parsed command line.
 */
final readonly class ConsoleCommandInput implements CommandInput
{
    public function __construct(private InputInterface $input)
    {
    }

    public function argument(string $name): ?string
    {
        return $this->input->hasArgument($name) ? $this->text($this->input->getArgument($name)) : null;
    }

    public function option(string $name): ?string
    {
        return $this->input->hasOption($name) ? $this->text($this->input->getOption($name)) : null;
    }

    public function flag(string $name): bool
    {
        return $this->input->hasOption($name) && $this->input->getOption($name) === true;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
