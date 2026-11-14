<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site\Command;

use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\RecordingOutput;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;

/**
 * Runs a command with no input and records what it writes.
 */
abstract class CommandTestCase extends TestCase
{
    protected TemporaryDirectory $directory;

    /** @var list<string> */
    protected array $lines = [];

    /** @var list<string> */
    protected array $errors = [];

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    protected function execute(Command $command): int
    {
        $this->lines = [];
        $this->errors = [];
        $input = new class () implements CommandInput {
            public function argument(string $name): ?string
            {
                return null;
            }

            public function option(string $name): ?string
            {
                return null;
            }

            public function flag(string $name): bool
            {
                return false;
            }
        };
        $output = new RecordingOutput();
        $status = $command->run($input, $output);
        $this->lines = $output->lines;
        $this->errors = $output->errors;

        return $status;
    }
}
