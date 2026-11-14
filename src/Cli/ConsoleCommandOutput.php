<?php

declare(strict_types=1);

namespace SightPress\Core\Cli;

use SightPress\Module\Console\CommandOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Where a module command writes: plain lines, written as given (no markup is interpreted).
 */
final readonly class ConsoleCommandOutput implements CommandOutput
{
    public function __construct(private OutputInterface $output)
    {
    }

    public function line(string $text = ''): void
    {
        $this->output->writeln($text, OutputInterface::OUTPUT_RAW);
    }

    public function error(string $text): void
    {
        $output = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
        $output->writeln($text, OutputInterface::OUTPUT_RAW);
    }
}
